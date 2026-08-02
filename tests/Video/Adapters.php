<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Encoder;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Hls;
use Utopia\Video\Packager;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

/**
 * What every backend has to get right, tested through the facades.
 *
 * Subclasses say which adapters to wire up and what to feed them; the tests
 * below are the same for all of them, so a new backend inherits the whole
 * contract by declaring four things.
 *
 * Lives outside both suite directories so PHPUnit never collects it directly.
 */
abstract class Adapters extends TestCase
{
    /**
     * An Encoder wired with the adapter under test.
     */
    abstract protected function encoder(): Encoder;

    /**
     * A Packager wired with the adapter under test.
     */
    abstract protected function packager(): Packager;

    /**
     * A source those adapters can read.
     */
    abstract protected function source(): string;

    /**
     * Whether the tools these adapters need are installed here.
     */
    abstract protected function available(): bool;

    /**
     * What getName() should report for each facade.
     *
     * @return array{encoder: string, packager: string}
     */
    abstract protected function names(): array;

    protected string $dir;

    protected function setUp(): void
    {
        if (! $this->available()) {
            $this->markTestSkipped('the tools this adapter needs are not installed');
        }

        $dir = \sys_get_temp_dir().'/utopia-adapters-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->remove($this->dir);
        }
    }

    protected function remove(string $dir): void
    {
        foreach (\glob($dir.'/*') ?: [] as $path) {
            \is_dir($path) ? $this->remove($path) : \unlink($path);
        }

        @\rmdir($dir);
    }

    protected function format(): X264
    {
        return (new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']);
    }

    public function testNamesEveryAdapter(): void
    {
        $names = $this->names();

        $this->assertSame($names['encoder'], $this->encoder()->getName());
        $this->assertSame($names['packager'], $this->packager()->getName());
    }

    public function testProbesASource(): void
    {
        $info = $this->encoder()->probe($this->source());

        $this->assertGreaterThan(0, $info->duration);
        $this->assertTrue($info->hasVideo || $info->hasAudio);
    }

    /**
     * Either facade can gate its input, so a caller holding only one of them
     * does not need the other just to check a file.
     */
    public function testAcceptsAUsableSource(): void
    {
        $this->assertTrue($this->encoder()->valid($this->source()));
        $this->assertTrue($this->packager()->valid($this->source()));
    }

    public function testRejectsAMissingSource(): void
    {
        $this->assertFalse($this->encoder()->valid($this->dir.'/nothing.mp4'));
    }

    public function testEncodesASingleFile(): void
    {
        $path = $this->encoder()
            ->open($this->source())
            ->format($this->format())
            ->add(new Representation(320, 240, 400, 64))
            ->encode($this->dir.'/out.mp4');

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, (int) \filesize($path));
    }

    /**
     * Both terminals end a job, so an adapter can serve one after another
     * without the first job's inputs still being registered.
     */
    public function testEncodesTwiceInARow(): void
    {
        $encoder = $this->encoder();

        foreach ([0, 1] as $round) {
            $path = $encoder
                ->open($this->source())
                ->format($this->format())
                ->add(new Representation(320, 240, 400, 64))
                ->encode($this->dir.'/out'.$round.'.mp4');

            $this->assertFileExists($path);
        }
    }

    public function testRefusesToEncodeALadder(): void
    {
        $this->expectExceptionMessage('use pack() for a ladder');

        $this->encoder()
            ->open($this->source())
            ->format($this->format())
            ->add(
                new Representation(320, 240, 300),
                new Representation(640, 480, 800),
            )
            ->encode($this->dir.'/two.mp4');
    }

    public function testPacksALadder(): void
    {
        $package = $this->packager()
            ->open($this->source())
            ->format($this->format())
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segment(2))
            ->pack($this->dir);

        $this->assertNotEmpty($package->segments());
        $this->assertGreaterThan(0, $package->size());

        foreach ($package->segments() as $segment) {
            $this->assertFileExists($segment->path);
        }
    }

    public function testRefusesToPackWithoutAnOutput(): void
    {
        $this->expectExceptionMessage('output format');

        $this->packager()
            ->open($this->source())
            ->add(new Representation(320, 240, 400))
            ->pack($this->dir);
    }

    public function testRefusesToPackWithoutALadder(): void
    {
        $this->expectExceptionMessage('representation');

        $this->packager()
            ->open($this->source())
            ->output(new Hls())
            ->pack($this->dir);
    }

    public function testReportsProgressThatOnlyClimbs(): void
    {
        $seen = [];

        $this->packager()
            ->open($this->source())
            ->format($this->format())
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segment(2))
            ->on(Packager::PROGRESS, function (mixed $progress) use (&$seen): void {
                $this->assertInstanceOf(Progress::class, $progress);
                $seen[] = $progress->percent;
            })
            ->pack($this->dir);

        $this->assertNotEmpty($seen);
        $this->assertSame(100.0, \end($seen));

        $sorted = $seen;
        \sort($sorted);
        $this->assertSame($sorted, $seen, 'progress should never go backwards');
    }

    /**
     * A finished job must not leak into the next one, on the facade or inside
     * the adapter it delegates to.
     */
    public function testReusingOnePackagerDoesNotRepeatEvents(): void
    {
        $packager = $this->packager();

        // A counter per round, so the two closures cannot share a variable and
        // hide the very accumulation this is looking for.
        $counts = [0, 0];

        foreach ([0, 1] as $round) {
            $packager
                ->open($this->source())
                ->format($this->format())
                ->add(new Representation(320, 240, 400, 64))
                ->output((new Hls())->segment(2))
                ->on(Packager::LOG, function () use (&$counts, $round): void {
                    $counts[$round]++;
                })
                ->pack($this->dir.'/round'.$round);
        }

        $this->assertSame(
            $counts[1],
            $counts[0],
            'the first job\'s listener should not still be firing during the second',
        );
    }

    public function testOpeningAgainForgetsThePreviousJob(): void
    {
        $packager = $this->packager()
            ->open($this->source())
            ->output(new Hls())
            ->add(new Representation(320, 240, 400));

        $packager->open($this->source());

        $this->expectExceptionMessage('output format');

        $packager->pack($this->dir);
    }

    public function testGrabsAStill(): void
    {
        $path = $this->encoder()->grab(
            $this->source(),
            $this->dir.'/still.jpg',
            (new Thumb())->width(160),
        );

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, (int) \filesize($path));
    }

    public function testTilesATimeline(): void
    {
        $sheet = $this->encoder()->tile($this->source(), $this->dir, (new Tile())->interval(2.0));

        $this->assertNotEmpty($sheet->images());
        $this->assertNotEmpty($sheet->cues());
        $this->assertNotNull($sheet->vtt());
        $this->assertStringStartsWith('WEBVTT', (string) \file_get_contents((string) $sheet->vtt()));
    }
}
