<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Process;

class ProcessTest extends TestCase
{
    public function testReadsStandardOutput(): void
    {
        $output = Process::read(['printf', "one\ntwo\n"]);

        $this->assertSame("one\ntwo\n", $output);
    }

    public function testStreamsOutputLineByLine(): void
    {
        $lines = [];

        Process::run(['printf', "a\nb\nc\n"], function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame(['a', 'b', 'c'], $lines);
    }

    public function testEmitsATrailingLineWithoutANewline(): void
    {
        $lines = [];

        Process::run(['printf', 'no-newline'], function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame(['no-newline'], $lines);
    }

    public function testSeparatesStandardErrorFromStandardOutput(): void
    {
        $out = [];
        $err = [];

        Process::run(
            ['sh', '-c', 'echo good; echo bad 1>&2'],
            function (string $line) use (&$out): void {
                $out[] = $line;
            },
            function (string $line) use (&$err): void {
                $err[] = $line;
            },
        );

        $this->assertSame(['good'], $out);
        $this->assertSame(['bad'], $err);
    }

    public function testCarriageReturnsSplitProgressBlocks(): void
    {
        $lines = [];

        Process::run(['printf', "one\rtwo\r"], function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame(['one', 'two'], $lines);
    }

    public function testFailureCarriesTheCommandAndItsComplaint(): void
    {
        try {
            Process::run(['sh', '-c', 'echo "went wrong" 1>&2; exit 3']);
            $this->fail('Expected the command to fail');
        } catch (Runtime $exception) {
            $this->assertSame(3, $exception->getCode());
            $this->assertStringContainsString('exit code 3', $exception->getMessage());
            $this->assertStringContainsString('went wrong', $exception->output());
            $this->assertSame('sh', $exception->command()[0]);
        }
    }

    public function testReturnsTheTailOfStandardError(): void
    {
        $errors = Process::run(['sh', '-c', 'echo "a note" 1>&2']);

        $this->assertSame('a note', $errors);
    }

    public function testStopsARunawayCommand(): void
    {
        $started = \microtime(true);

        try {
            Process::run(['sleep', '30'], timeout: 1);
            $this->fail('Expected the command to time out');
        } catch (Runtime $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertSame('sleep', $exception->command()[0]);
        }

        $this->assertLessThan(10, \microtime(true) - $started);
    }

    /**
     * A command that has to be stopped is the one most in need of explaining, so
     * whatever it complained about on the way out is kept rather than discarded
     * along with its pipes.
     */
    public function testATimedOutCommandKeepsItsComplaint(): void
    {
        try {
            Process::run(['sh', '-c', 'echo "half way in" 1>&2; sleep 30'], timeout: 1);
            $this->fail('Expected the command to time out');
        } catch (Runtime $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertStringContainsString('half way in', $exception->output());
        }
    }

    /**
     * A command that had to be stopped is worth telling apart from one that
     * failed on its own. proc_close() has nothing left to report once the child
     * has been reaped, so the signal it died of is what the failure carries, by
     * the shell's convention of 128 plus the signal number.
     */
    public function testAStoppedCommandReportsHowItWasStopped(): void
    {
        try {
            Process::run(['sleep', '30'], timeout: 1);
            $this->fail('Expected the command to time out');
        } catch (Runtime $exception) {
            $this->assertSame(143, $exception->getCode(), 'expected SIGTERM to be reported');
        }
    }

    /**
     * Asking is not the same as insisting. A command that will not take the
     * hint is killed outright once its grace period is up, and says so.
     */
    public function testACommandThatWillNotStopIsKilled(): void
    {
        try {
            Process::run(['sh', '-c', 'trap "" TERM; sleep 30'], timeout: 1);
            $this->fail('Expected the command to time out');
        } catch (Runtime $exception) {
            $this->assertSame(137, $exception->getCode(), 'expected SIGKILL to be reported');
        }
    }

    /**
     * Collected output is held in memory, so a command that never stops talking
     * has to be cut off rather than allowed to exhaust the process running it.
     * The tail of stderr has always been bounded; this is the other half.
     */
    public function testACollectorRefusesMoreThanItWasAllowed(): void
    {
        $output = '';

        try {
            Process::run(['sh', '-c', 'yes utopia'], Process::collector($output, 64));
            $this->fail('Expected the collector to give up');
        } catch (Runtime $exception) {
            $this->assertStringContainsString('more than 64 bytes', $exception->getMessage());
        }
    }

    /**
     * Nothing obliges a stream to send line breaks, and a backend writing
     * something binary never will, so the buffer is handed over once it has
     * grown past a plausible line rather than held until the command ends.
     */
    public function testAnEndlessLineIsNotBufferedForever(): void
    {
        $lines = [];

        Process::run(
            ['sh', '-c', 'printf "%0200000d" 1'],
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertGreaterThan(1, \count($lines), 'one unbroken line should arrive in pieces');
        $this->assertSame(200000, \array_sum(\array_map('strlen', $lines)));
    }

    public function testAThrowingListenerDoesNotLeaveTheChildRunning(): void
    {
        $marker = \sys_get_temp_dir().'/utopia-process-'.\bin2hex(\random_bytes(6));

        try {
            $failure = null;

            try {
                Process::run(
                    [
                        PHP_BINARY,
                        '-r',
                        'echo "ready\n"; flush(); usleep(500000); file_put_contents($argv[1], "alive");',
                        $marker,
                    ],
                    static function (): void {
                        throw new \LogicException('listener stopped');
                    },
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            $this->assertInstanceOf(\LogicException::class, $failure);
            $this->assertSame('listener stopped', $failure->getMessage());

            \usleep(700000);
            $this->assertFileDoesNotExist($marker, 'the child survived after its listener threw');
        } finally {
            @\unlink($marker);
        }
    }

    public function testMissingBinaryIsAFailure(): void
    {
        $this->expectException(Runtime::class);

        Process::run(['utopia-streaming-does-not-exist']);
    }

    public function testEmptyCommandIsAFailure(): void
    {
        $this->expectException(Runtime::class);

        Process::run([]);
    }

    public function testReportsWhetherABinaryIsUsable(): void
    {
        $this->assertFalse(Process::exists('utopia-streaming-does-not-exist'));
    }
}
