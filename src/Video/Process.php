<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Exception\Runtime;

/**
 * Minimal process runner built on proc_open.
 *
 * Commands are passed as argv arrays so nothing is ever handed to a shell.
 * Both pipes are streamed line by line, which is what lets long running
 * encodes report progress while they work.
 *
 * @internal
 */
final class Process
{
    private const TAIL = 8192;

    /**
     * Longest run of interrupted waits to sit through before giving up.
     *
     * A signal arriving mid-wait makes stream_select report failure without
     * anything actually being wrong, so a handful in a row are shrugged off; a
     * stream that keeps reporting failure is a real one.
     */
    private const RETRIES = 10;

    /** Bytes of one unbroken line to hold before emitting it anyway. */
    private const LINE = 65536;

    /** Seconds a killed command is given to explain itself. */
    private const GRACE = 2.0;

    /** Bytes of collected output to hold before giving up on a command. */
    private const OUTPUT = 8388608;

    /**
     * @param  list<string>  $command
     * @param  callable(string):void|null  $stdout  Called once per line written to stdout.
     * @param  callable(string):void|null  $stderr  Called once per line written to stderr.
     * @param  int  $timeout  Seconds to allow the command to run; 0 disables the limit.
     * @return string The tail of stderr, which is where ffmpeg reports failures.
     *
     * @throws Runtime
     */
    public static function run(
        array $command,
        ?callable $stdout = null,
        ?callable $stderr = null,
        int $timeout = 0,
    ): string {
        if ($command === []) {
            throw new Runtime('Cannot run an empty command');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];

        // A missing binary raises a warning before returning false, and callers
        // should get the exception rather than whatever the warning handler does.
        $process = @\proc_open($command, $descriptors, $pipes);

        if (! \is_resource($process)) {
            throw new Runtime('Unable to start "'.$command[0].'"', $command);
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $buffers = [1 => '', 2 => ''];
        $errors = '';
        $started = \microtime(true);
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        $interrupted = 0;
        $closed = false;

        try {
            while ($open !== []) {
                // Checked before the wait rather than after the read, so a command
                // that has gone quiet is still stopped on time.
                if ($timeout > 0 && (\microtime(true) - $started) > $timeout) {
                    $failure = self::halt(
                        $process,
                        $open,
                        $errors,
                        'Command "'.$command[0].'" timed out after '.$timeout.'s',
                        $command,
                    );
                    $closed = true;

                    throw $failure;
                }

                $read = \array_values($open);
                $write = null;
                $except = null;

                if (@\stream_select($read, $write, $except, 0, 200000) === false) {
                    if (++$interrupted <= self::RETRIES) {
                        continue;
                    }

                    $failure = self::halt(
                        $process,
                        $open,
                        $errors,
                        'Lost contact with "'.$command[0].'" while reading its output',
                        $command,
                    );
                    $closed = true;

                    throw $failure;
                }

                $interrupted = 0;

                foreach ($open as $key => $pipe) {
                    if (! \in_array($pipe, $read, true)) {
                        continue;
                    }

                    $chunk = \fread($pipe, 8192);

                    if ($chunk === false || $chunk === '') {
                        if (\feof($pipe)) {
                            if ($buffers[$key] !== '') {
                                self::emit($key, $buffers[$key], $stdout, $stderr);
                                $buffers[$key] = '';
                            }

                            \fclose($pipe);
                            unset($open[$key]);
                        }

                        continue;
                    }

                    if ($key === 2) {
                        $errors = \substr($errors.$chunk, -self::TAIL);
                    }

                    $buffers[$key] .= $chunk;

                    while (($break = \strpos($buffers[$key], "\n")) !== false) {
                        $line = \substr($buffers[$key], 0, $break);
                        $buffers[$key] = \substr($buffers[$key], $break + 1);
                        self::emit($key, $line, $stdout, $stderr);
                    }

                    // ffmpeg separates progress blocks with \r when writing to a terminal.
                    while (($break = \strpos($buffers[$key], "\r")) !== false) {
                        $line = \substr($buffers[$key], 0, $break);
                        $buffers[$key] = \substr($buffers[$key], $break + 1);

                        if (\trim($line) !== '') {
                            self::emit($key, $line, $stdout, $stderr);
                        }
                    }

                    // Nothing says a stream has to send line breaks, and a binary
                    // one never will, so a buffer that has grown past a plausible
                    // line is handed over as it stands rather than held forever.
                    if (\strlen($buffers[$key]) >= self::LINE) {
                        self::emit($key, $buffers[$key], $stdout, $stderr);
                        $buffers[$key] = '';
                    }
                }
            }

            $status = \proc_close($process);
            $closed = true;

            if ($status !== 0) {
                throw new Runtime(
                    'Command "'.$command[0].'" failed with exit code '.$status,
                    $command,
                    \trim($errors),
                    $status,
                );
            }

            return \trim($errors);
        } finally {
            // A user listener is application code and may throw. It must not
            // strand the child process merely because control left the read
            // loop by a route other than our own timeout/error exceptions.
            if (! $closed) {
                self::halt(
                    $process,
                    $open,
                    $errors,
                    'Command "'.$command[0].'" was interrupted while reading its output',
                    $command,
                );
            }
        }
    }

    /**
     * Runs a command and returns everything it wrote to stdout.
     *
     * @param  list<string>  $command
     *
     * @throws Runtime
     */
    public static function read(array $command, int $timeout = 0): string
    {
        $output = '';

        self::run($command, self::collector($output), null, $timeout);

        return $output;
    }

    /**
     * A line listener that appends everything it is given to a string.
     *
     * Collected output is held in memory, so a ceiling is part of the bargain:
     * a command that will not stop talking is given up on rather than allowed
     * to exhaust the process that ran it. The tail of stderr is bounded the
     * same way inside the read loop. Pass 0 to collect without a limit.
     *
     * @param  string  $into  Filled in as lines arrive.
     * @param  int  $limit  Bytes to hold at most; 0 for no limit.
     * @return callable(string):void
     */
    public static function collector(string &$into, int $limit = self::OUTPUT): callable
    {
        return static function (string $line) use (&$into, $limit): void {
            if ($limit > 0 && \strlen($into) + \strlen($line) + 1 > $limit) {
                throw new Runtime('Command produced more than '.$limit.' bytes of output');
            }

            $into .= $line."\n";
        };
    }

    /**
     * Reports whether a binary can be executed at all.
     */
    public static function exists(string $binary): bool
    {
        try {
            self::run([$binary, '-version'], timeout: 10);

            return true;
        } catch (Runtime) {
            return false;
        }
    }

    /**
     * Ends a command that cannot be waited on any longer, and describes why.
     *
     * Asking it to stop before insisting is the point: a backend given a chance
     * to exit writes the reason it was unhappy to stderr on the way out, and
     * closing the pipes first would throw away the only useful part of the
     * failure. Whatever it manages to say inside the grace period is kept, and
     * only a process still running afterwards is killed outright.
     *
     * @param  resource  $process
     * @param  array<int, resource>  $open
     * @param  list<string>  $command
     */
    private static function halt(
        $process,
        array $open,
        string $errors,
        string $message,
        array $command,
    ): Runtime {
        \proc_terminate($process, 15);

        $deadline = \microtime(true) + self::GRACE;
        $status = \proc_get_status($process);

        while ($status['running'] && \microtime(true) < $deadline) {
            // Every pipe, not stderr alone: a backend blocked writing to a pipe
            // nobody is draining cannot reach its own exit, and would have to be
            // killed for it rather than being allowed to take the hint.
            foreach ($open as $key => $pipe) {
                $chunk = \fread($pipe, 8192);

                if ($key === 2 && \is_string($chunk) && $chunk !== '') {
                    $errors = \substr($errors.$chunk, -self::TAIL);
                }
            }

            \usleep(20000);
            $status = \proc_get_status($process);
        }

        if ($status['running']) {
            \proc_terminate($process, 9);

            // Being reaped is what fills in how it ended, and a killed process
            // is gone almost at once. Without waiting for that the status still
            // reads "running" and the failure carries no code at all.
            for ($attempt = 0; $attempt < 50 && $status['running']; $attempt++) {
                \usleep(2000);
                $status = \proc_get_status($process);
            }
        }

        foreach ($open as $handle) {
            \fclose($handle);
        }

        \proc_close($process);

        return new Runtime($message, $command, \trim($errors), self::ended($status));
    }

    /**
     * How a command that had to be stopped ended, as an exit code.
     *
     * proc_close() reports -1 once proc_get_status() has reaped the child, so
     * the status is the only thing still holding the truth. A signalled process
     * has no exit code of its own, so the shell's convention of 128 plus the
     * signal is borrowed: it tells a command that took the hint (143) from one
     * that had to be killed (137) from one that failed on its own.
     *
     * @param  array{running: bool, signaled: bool, termsig: int, exitcode: int}  $status
     */
    private static function ended(array $status): int
    {
        if ($status['signaled'] && $status['termsig'] > 0) {
            return 128 + $status['termsig'];
        }

        return \max(0, $status['exitcode']);
    }

    /**
     * @param  callable(string):void|null  $stdout
     * @param  callable(string):void|null  $stderr
     */
    private static function emit(int $key, string $line, ?callable $stdout, ?callable $stderr): void
    {
        $line = \rtrim($line, "\r\n");
        $listener = $key === 1 ? $stdout : $stderr;

        if ($listener !== null) {
            $listener($line);
        }
    }
}
