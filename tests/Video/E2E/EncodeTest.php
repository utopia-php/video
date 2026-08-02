<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Exception\Input;
use Utopia\Video\Format\Copy;
use Utopia\Video\Format\HEVC;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Hls;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Encoder;
use Utopia\Video\Packager;

class EncodeTest extends Base
{
    public function testEncodesASingleFile(): void
    {
        $encoder = new Encoder();

        $path = $encoder
            ->open(self::video())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->encode($this->dir.'/out.mp4');

        $this->assertWritten($path);

        $info = $encoder->probe($path);
        $this->assertSame(320, $info->width);
        $this->assertSame(240, $info->height);
        $this->assertTrue($info->hasAudio);
        $this->assertEqualsWithDelta(8.0, $info->duration, 1.0);
    }

    /**
     * A rung asks for an exact frame size so the size advertised to players is
     * the size delivered. A source of a different shape is fitted inside the
     * box rather than stretched to fill it.
     */
    public function testFitsASourceOfADifferentShapeIntoTheRequestedBox(): void
    {
        $encoder = new Encoder();

        $path = $encoder
            ->open(self::video())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->add(new Representation(640, 360, 500, 64))
            ->encode($this->dir.'/wide.mp4');

        $info = $encoder->probe($path);

        $this->assertSame(640, $info->width);
        $this->assertSame(360, $info->height);
    }

    public function testEncodesWithoutALadder(): void
    {
        $path = (new Encoder())
            ->open(self::video())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->encode($this->dir.'/plain.mp4');

        $this->assertWritten($path);
    }

    public function testRepackagesWithoutReEncoding(): void
    {
        $started = \microtime(true);

        $path = (new Encoder())
            ->open(self::video())
            ->format(new Copy())
            ->encode($this->dir.'/copy.mkv');

        $this->assertWritten($path);
        $this->assertLessThan(30, \microtime(true) - $started);
    }

    public function testEncodesHevc(): void
    {
        $encoder = new Encoder();

        $path = $encoder
            ->open(self::video())
            ->format((new HEVC())->crf(35)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->encode($this->dir.'/hevc.mp4');

        $this->assertWritten($path);
        $this->assertSame('hevc', $encoder->probe($path)->videoCodec);
    }

    public function testPackagesHevcAsFragmentedHls(): void
    {
        $package = (new Packager())
            ->open(self::video())
            ->format((new HEVC())->crf(35)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segments(Hls::FMP4)->segment(2))
            ->pack($this->dir);

        $this->assertNotEmpty($package->segments());
        $this->assertWritten($this->dir.'/master.m3u8');
    }

    public function testPackagesHevcAsCmaf(): void
    {
        $package = (new Packager())
            ->open(self::video())
            ->format((new HEVC())->crf(35)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Cmaf())->segment(2))
            ->pack($this->dir);

        $this->assertNotEmpty($package->segments());
        $this->assertWritten($this->dir.'/manifest.mpd');
        $this->assertWritten($this->dir.'/master.m3u8');
    }

    public function testEncodesAnAudioOnlySource(): void
    {
        $encoder = new Encoder();

        $path = $encoder
            ->open(self::audio())
            ->format(new X264())
            ->add(new Representation(0, 0, 1, 96))
            ->encode($this->dir.'/audio.m4a');

        $this->assertWritten($path);

        $info = $encoder->probe($path);
        $this->assertTrue($info->hasAudio);
        $this->assertFalse($info->hasVideo);
    }

    public function testProgressReachesCompletion(): void
    {
        $seen = [];

        (new Encoder())
            ->open(self::video())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->on(Encoder::PROGRESS, function (Progress $progress) use (&$seen): void {
                $seen[] = $progress->percent;
            })
            ->encode($this->dir.'/progress.mp4');

        $this->assertNotEmpty($seen);
        $this->assertSame(100.0, \end($seen));

        foreach ($seen as $percent) {
            $this->assertGreaterThanOrEqual(0.0, $percent);
            $this->assertLessThanOrEqual(100.0, $percent);
        }
    }

    public function testEncodingALadderIsRejected(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('use pack() for a ladder');

        (new Encoder())
            ->open(self::video())
            ->format(new X264())
            ->add(
                new Representation(320, 240, 300),
                new Representation(640, 480, 800),
            )
            ->encode($this->dir.'/two.mp4');
    }

    public function testAdapterCanBeReusedForASecondJob(): void
    {
        $encoder = new Encoder();

        $first = $encoder
            ->open(self::video())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->encode($this->dir.'/first.mp4');

        $second = $encoder
            ->open(self::silent())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->add(new Representation(160, 120, 200))
            ->encode($this->dir.'/second.mp4');

        $this->assertWritten($first);
        $this->assertWritten($second);
        $this->assertSame(160, $encoder->probe($second)->width);
    }
}
