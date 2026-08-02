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

        while ($open !== []) {
            $read = \array_values($open);
            $write = null;
            $except = null;

            if (@\stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }

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
            }

            if ($timeout > 0 && (\microtime(true) - $started) > $timeout) {
                foreach ($open as $pipe) {
                    \fclose($pipe);
                }

                \proc_terminate($process, 9);
                \proc_close($process);

                throw new Runtime(
                    'Command "'.$command[0].'" timed out after '.$timeout.'s',
                    $command,
                    $errors,
                );
            }
        }

        $status = \proc_close($process);

        if ($status !== 0) {
            throw new Runtime(
                'Command "'.$command[0].'" failed with exit code '.$status,
                $command,
                \trim($errors),
                $status,
            );
        }

        return \trim($errors);
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

        self::run($command, function (string $line) use (&$output): void {
            $output .= $line."\n";
        }, null, $timeout);

        return $output;
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
