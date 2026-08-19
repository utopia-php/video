<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Adapter\Encoder as EncoderAdapter;
use Utopia\Video\Adapter\Packager as PackagerAdapter;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\Copy;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Packager;
use Utopia\Video\Progress;
use Utopia\Video\Representation;

class PackagerTest extends TestCase
{
    private string $file;

    private string $dir;

    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-packager-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
        $this->file = $dir.'/source.mp4';
        \file_put_contents($this->file, 'source');
    }

    protected function tearDown(): void
    {
        $this->clean($this->dir);
    }

    private function clean(string $dir): void
    {
        foreach (\glob($dir.'/*') ?: [] as $path) {
            \is_dir($path) ? $this->clean($path) : \unlink($path);
        }

        @\rmdir($dir);
    }

    private function packager(
        ?PackagerAdapter $adapter = null,
        ?EncoderAdapter $encoder = null,
    ): Packager {
        return new Packager(
            adapter: $adapter ?? new FakePackager(),
            encoder: $encoder ?? new FakeEncoder(),
            probe: new FakeProbe(),
        );
    }

    public function testDelegatesProbing(): void
    {
        $info = $this->packager()->probe($this->file);

        $this->assertSame(10.0, $info->duration);
        $this->assertTrue($this->packager()->valid($this->file));
    }

    public function testReportsWhichBackendIsWorking(): void
    {
        $this->assertSame('fake', $this->packager()->getName());
    }

    /**
     * A packager that can also encode gets the source directly, so the whole
     * job is one pass instead of writing an intermediate file first.
     */
    public function testPackagerThatEncodesReceivesTheSourceItself(): void
    {
        $encoder = new FakeEncoder();
        $adapter = new FusedPackager();
        $output = new Cmaf();
        $rep = new Representation(1280, 720, 2538);

        $this->packager($adapter, $encoder)
            ->open($this->file)
            ->format(new X264())
            ->add($rep)
            ->output($output)
            ->pack($this->dir.'/out');

        $this->assertSame([$this->file], $adapter->opened);
        $this->assertSame($output, $adapter->output);
        $this->assertSame([$rep], $adapter->reps);
        $this->assertNull($encoder->opened, 'the source should not be encoded separately');
    }

    /**
     * A packager that cannot encode is handed one finished file per rung.
     *
     * This is the seam an alternative packaging backend plugs into: implement
     * Adapter\Packager and nothing in the facade has to change.
     */
    public function testPackagerThatCannotEncodeReceivesFinishedFiles(): void
    {
        $encoder = new FakeEncoder();
        $adapter = new FakePackager();

        $this->packager($adapter, $encoder)
            ->open($this->file)
            ->format(new X264())
            ->add(
                new Representation(640, 360, 800),
                new Representation(1280, 720, 2538),
            )
            ->output(new Cmaf())
            ->pack($this->dir.'/out');

        $this->assertSame(2, $encoder->encoded);
        $this->assertCount(2, $adapter->opened);
        $this->assertStringEndsWith('360p.mp4', $adapter->opened[0]);
        $this->assertStringEndsWith('720p.mp4', $adapter->opened[1]);
        $this->assertSame('360p', $adapter->tagged[0]?->name);
    }

    public function testStagedIntermediatesAreCleanedUp(): void
    {
        $this->packager()
            ->open($this->file)
            ->add(new Representation(640, 360, 800))
            ->output(new Cmaf())
            ->pack($this->dir.'/out');

        $this->assertDirectoryDoesNotExist($this->dir.'/out/.staging');
    }

    public function testStagedEncodingUsesTheSegmentLengthAsItsKeyframeCadence(): void
    {
        $encoder = new FakeEncoder();

        $this->packager(encoder: $encoder)
            ->open($this->file)
            ->format(new X264())
            ->add(new Representation(640, 360, 800))
            ->output((new Cmaf())->segment(4))
            ->pack($this->dir.'/out');

        $this->assertNotNull($encoder->format);
        $this->assertSame(4.0, $encoder->format->interval());
    }

    public function testAnExplicitStagedKeyframeCadenceStillWins(): void
    {
        $encoder = new FakeEncoder();

        $this->packager(encoder: $encoder)
            ->open($this->file)
            ->format((new X264())->keyframe(2))
            ->add(new Representation(640, 360, 800))
            ->output((new Cmaf())->segment(6))
            ->pack($this->dir.'/out');

        $this->assertNotNull($encoder->format);
        $this->assertSame(2.0, $encoder->format->interval());
    }

    public function testAnImpossibleStagedKeyframeCadenceIsRejectedBeforeEncoding(): void
    {
        $encoder = new FakeEncoder();

        try {
            $this->packager(encoder: $encoder)
                ->open($this->file)
                ->format((new X264())->keyframe(10))
                ->add(new Representation(640, 360, 800))
                ->output((new Cmaf())->segment(4))
                ->pack($this->dir.'/out');
            $this->fail('Expected the keyframe cadence to be rejected');
        } catch (Unsupported $exception) {
            $this->assertStringContainsString('A keyframe every 10s cannot cut 4s segments', $exception->getMessage());
        }

        $this->assertSame(0, $encoder->encoded);
    }

    public function testStreamCopyIsRejectedBeforeStagedEncodingStarts(): void
    {
        $encoder = new FakeEncoder();

        try {
            $this->packager(encoder: $encoder)
                ->open($this->file)
                ->format(new Copy())
                ->add(new Representation(640, 360, 800))
                ->output(new Cmaf())
                ->pack($this->dir.'/out');
            $this->fail('Expected stream copy to be rejected');
        } catch (Unsupported $exception) {
            $this->assertStringContainsString('Stream copy cannot build an adaptive package', $exception->getMessage());
        }

        $this->assertSame(0, $encoder->encoded);
    }

    public function testDuplicateNamesAreRejectedBeforeStagedEncodingStarts(): void
    {
        $encoder = new FakeEncoder();

        try {
            $this->packager(encoder: $encoder)
                ->open($this->file)
                ->add(
                    new Representation(640, 360, 800),
                    new Representation(480, 360, 500),
                )
                ->output(new Cmaf())
                ->pack($this->dir.'/out');
            $this->fail('Expected duplicate names to be rejected');
        } catch (Input $exception) {
            $this->assertStringContainsString('Representation name "360p" is used more than once', $exception->getMessage());
        }

        $this->assertSame(0, $encoder->encoded);
    }

    public function testStagedProgressClimbsOnceAcrossEveryRung(): void
    {
        $seen = [];

        $this->packager()
            ->open($this->file)
            ->format(new X264())
            ->add(
                new Representation(640, 360, 800),
                new Representation(1280, 720, 2538),
            )
            ->output(new Cmaf())
            ->on(Packager::PROGRESS, function (Progress $progress) use (&$seen): void {
                $seen[] = $progress->percent;
            })
            ->pack($this->dir.'/out');

        $this->assertNotEmpty($seen);
        $this->assertSame(100.0, \end($seen));

        $sorted = $seen;
        \sort($sorted);
        $this->assertSame($sorted, $seen, 'progress should never go backwards');
    }

    public function testListenersReachTheAdapterDoingTheWork(): void
    {
        $adapter = new FusedPackager();
        $lines = [];

        $this->packager($adapter)
            ->open($this->file)
            ->add(new Representation(1280, 720, 2538))
            ->output(new Cmaf())
            ->on(Packager::LOG, function (string $line) use (&$lines): void {
                $lines[] = $line;
            })
            ->pack($this->dir.'/out');

        $this->assertSame(['packing'], $lines);
    }

    public function testStagedLogsAreForwardedToo(): void
    {
        $lines = [];

        $this->packager()
            ->open($this->file)
            ->add(new Representation(640, 360, 800))
            ->output(new Cmaf())
            ->on(Packager::LOG, function (string $line) use (&$lines): void {
                $lines[] = $line;
            })
            ->pack($this->dir.'/out');

        $this->assertSame(['packing'], $lines);
    }

    public function testOpeningAgainForgetsThePreviousJob(): void
    {
        $packager = $this->packager()
            ->open($this->file)
            ->add(new Representation(1280, 720, 2538))
            ->output(new Cmaf());

        $packager->open($this->file);

        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('No output format');

        $packager->pack('/out');
    }

    public function testPackingWithoutASourceIsAFailure(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('No source has been opened');

        $this->packager()->pack('/out');
    }

    public function testPackingWithoutALadderIsAFailure(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('At least one representation');

        $this->packager()->open($this->file)->output(new Cmaf())->pack('/out');
    }

    public function testOpeningAMissingSourceIsAFailure(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('does not exist');

        $this->packager()->open('/nowhere/nothing.mp4');
    }

    /**
     * A packager that also encodes needs no separate encoder handed to it.
     */
    public function testAFusedAdapterIsItsOwnEncoder(): void
    {
        $adapter = new FusedPackager();

        (new Packager($adapter, probe: new FakeProbe()))
            ->open($this->file)
            ->add(new Representation(1280, 720, 2538))
            ->output(new Cmaf())
            ->pack($this->dir.'/out');

        $this->assertSame([$this->file], $adapter->opened);
    }
}
