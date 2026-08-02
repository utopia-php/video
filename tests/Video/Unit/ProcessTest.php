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
        }

        $this->assertLessThan(10, \microtime(true) - $started);
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
