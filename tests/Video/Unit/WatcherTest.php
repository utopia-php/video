<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Adapter\Watcher;
use Utopia\Video\Written;

class WatcherTest extends TestCase
{
    private string $dir;

    /** @var list<Written> */
    private array $reported = [];

    private Watcher $watcher;

    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-watcher-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
        $this->reported = [];
        $this->watcher = new Watcher($dir, function (Written $written): void {
            $this->reported[] = $written;
        });
    }

    protected function tearDown(): void
    {
        // scandir rather than glob: GLOB_BRACE does not exist on musl.
        foreach (\scandir($this->dir) ?: [] as $name) {
            if (\is_file($this->dir.'/'.$name)) {
                \unlink($this->dir.'/'.$name);
            }
        }

        @\rmdir($this->dir);
    }

    private function write(string $name, string $body = 'bytes'): void
    {
        \file_put_contents($this->dir.'/'.$name, $body);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return \array_map(static fn (Written $written): string => $written->file, $this->reported);
    }

    public function testASegmentIsFinishedOnceTheNextOneExists(): void
    {
        $this->write('stream_0_0000.ts', 'aaaa');
        $this->watcher->scan();
        $this->assertSame([], $this->names(), 'the newest segment may still be growing');

        $this->write('stream_0_0001.ts');
        $this->watcher->scan();
        $this->assertSame(['stream_0_0000.ts'], $this->names());
        $this->assertSame(4, $this->reported[0]->size);
        $this->assertSame($this->dir.'/stream_0_0000.ts', $this->reported[0]->path);
    }

    public function testTheLastSegmentWaitsForTheEndOfTheRun(): void
    {
        $this->write('stream_0_0000.ts');
        $this->write('stream_0_0001.ts');
        $this->watcher->scan();

        $this->assertSame(['stream_0_0000.ts'], $this->names());

        $this->watcher->scan(final: true);
        $this->assertSame(['stream_0_0000.ts', 'stream_0_0001.ts'], $this->names());
    }

    /**
     * Each rung of a ladder numbers its own segments, so one rung running ahead
     * must not release another rung's newest file.
     */
    public function testEveryRungKeepsItsOwnSequence(): void
    {
        $this->write('stream_0_0000.ts');
        $this->write('stream_0_0001.ts');
        $this->write('stream_1_0000.ts');
        $this->watcher->scan();

        $this->assertSame(['stream_0_0000.ts'], $this->names());
    }

    public function testAFileStillBeingWrittenIsNeverReportedButProvesTheOneBefore(): void
    {
        $this->write('stream_0_0000.ts');
        $this->write('stream_0_0001.ts.tmp');
        $this->watcher->scan();

        $this->assertSame(['stream_0_0000.ts'], $this->names());

        $this->watcher->scan(final: true);
        $this->assertSame(['stream_0_0000.ts'], $this->names(), 'a .tmp left at the end is not a segment');
    }

    public function testManifestsAreNeverReported(): void
    {
        $this->write('master.m3u8');
        $this->write('stream_0.m3u8');
        $this->write('stream_1.m3u8');
        $this->write('manifest.mpd');
        $this->watcher->scan(final: true);

        $this->assertSame([], $this->names());
    }

    /**
     * DASH numbers its init segments by rung, which looks like a sequence; an
     * init segment is only released at the end however many rungs there are.
     */
    public function testInitialisationSegmentsWaitForTheEnd(): void
    {
        $this->write('stream_init_0.m4s');
        $this->write('stream_init_1.m4s');
        $this->write('stream_0_init.mp4');
        $this->watcher->scan();

        $this->assertSame([], $this->names());

        $this->watcher->scan(final: true);
        $this->assertEqualsCanonicalizing(
            ['stream_init_0.m4s', 'stream_init_1.m4s', 'stream_0_init.mp4'],
            $this->names(),
        );
    }

    /**
     * A listener is free to delete what it was given. The sequence must still
     * advance, and the ledger must still know what was shipped and how big.
     */
    public function testAListenerMayDeleteWhatItIsGiven(): void
    {
        $watcher = new Watcher($this->dir, function (Written $written): void {
            $this->reported[] = $written;
            \unlink($written->path);
        });

        $this->write('stream_chunk_0_00001.m4s', 'aaa');
        $this->write('stream_chunk_0_00002.m4s', 'bb');
        $watcher->scan();
        $this->write('stream_chunk_0_00003.m4s', 'c');
        $watcher->scan();
        $watcher->scan(final: true);

        $this->assertSame(
            ['stream_chunk_0_00001.m4s', 'stream_chunk_0_00002.m4s', 'stream_chunk_0_00003.m4s'],
            $this->names(),
        );
        $this->assertSame(
            ['stream_chunk_0_00001.m4s' => 3, 'stream_chunk_0_00002.m4s' => 2, 'stream_chunk_0_00003.m4s' => 1],
            $watcher->ledger(),
        );
        $this->assertSame([], \glob($this->dir.'/*.m4s'));
    }

    public function testNothingIsReportedTwice(): void
    {
        $this->write('stream_0_0000.ts');
        $this->write('stream_0_0001.ts');
        $this->watcher->scan();
        $this->watcher->scan();
        $this->watcher->scan(final: true);
        $this->watcher->scan(final: true);

        $this->assertSame(['stream_0_0000.ts', 'stream_0_0001.ts'], $this->names());
    }

    /**
     * A source kept next to the output must never reach a listener that deletes
     * what it is given, and an earlier, longer run's segments must not count as
     * proof that this run's are finished.
     */
    public function testFilesFromBeforeTheRunAreLeftAlone(): void
    {
        $this->write('source.mp4', 'source');
        $this->write('stream_chunk_0_00001.m4s', 'old one');
        $this->write('stream_chunk_0_00002.m4s', 'old two');
        $this->write('stream_chunk_0_00003.m4s', 'old three');

        $watcher = new Watcher($this->dir, function (Written $written): void {
            $this->reported[] = $written;
        });

        // This run rewrites the first segment and is part way into the second.
        \unlink($this->dir.'/stream_chunk_0_00001.m4s');
        $this->write('stream_chunk_0_00001.m4s', 'new');
        $watcher->scan();

        $this->assertSame([], $this->names(), 'the old second segment proves nothing about the new first');

        // A real rewrite differs in size or second; see the Watcher's own note
        // on what it cannot tell apart.
        \unlink($this->dir.'/stream_chunk_0_00002.m4s');
        $this->write('stream_chunk_0_00002.m4s', 'new second');
        $watcher->scan();
        $watcher->scan(final: true);

        $this->assertSame(['stream_chunk_0_00001.m4s', 'stream_chunk_0_00002.m4s'], $this->names());
        $this->assertSame(3, $this->reported[0]->size);
    }

    public function testHiddenEntriesAndDirectoriesAreIgnored(): void
    {
        \mkdir($this->dir.'/.staging');
        $this->write('.hidden_0001.ts');
        $this->watcher->scan(final: true);
        @\rmdir($this->dir.'/.staging');

        $this->assertSame([], $this->names());
    }
}
