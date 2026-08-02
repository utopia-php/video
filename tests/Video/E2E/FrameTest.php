<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Exception\Input;
use Utopia\Video\Process;
use Utopia\Video\Encoder;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

class FrameTest extends Base
{
    /**
     * @return array{0: int, 1: int}
     */
    private function size(string $path): array
    {
        $size = \getimagesize($path);

        $this->assertNotFalse($size, $path.' is not a readable image');

        return [$size[0], $size[1]];
    }

    public function testGrabsAPosterFrame(): void
    {
        $path = (new Encoder())->grab(self::video(), $this->dir.'/poster.jpg');

        $this->assertSame($this->dir.'/poster.jpg', $path);
        $this->assertWritten($path);
        $this->assertSame([320, 240], $this->size($path));
    }

    public function testGrabsAFrameAtAGivenMoment(): void
    {
        $path = (new Encoder())->grab(
            self::video(),
            $this->dir.'/at.jpg',
            (new Thumb())->time(4.0),
        );

        $this->assertWritten($path);
    }

    public function testHonoursTheRequestedWidth(): void
    {
        $path = (new Encoder())->grab(
            self::video(),
            $this->dir.'/wide.jpg',
            (new Thumb())->width(160),
        );

        [$width, $height] = $this->size($path);

        $this->assertSame(160, $width);
        $this->assertSame(120, $height, 'height should follow the source aspect');
    }

    public function testWritesPngWhenAsked(): void
    {
        $path = (new Encoder())->grab(self::video(), $this->dir.'/poster.png');

        $this->assertWritten($path);

        $size = \getimagesize($path);
        $this->assertNotFalse($size);
        $this->assertSame('image/png', $size['mime']);
    }

    public function testCreatesTheOutputDirectory(): void
    {
        $path = (new Encoder())->grab(self::video(), $this->dir.'/nested/poster.jpg');

        $this->assertWritten($path);
    }

    /**
     * Audio files often carry their artwork as a single frame video stream,
     * which is worth pulling out even though the file is not a video.
     */
    public function testGrabsEmbeddedArtwork(): void
    {
        $cover = $this->dir.'/cover.jpg';
        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=c=red:s=320x320:d=1',
            '-frames:v', '1', $cover,
        ], timeout: 60);

        $source = $this->dir.'/with-art.mp3';
        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-i', $cover,
            '-map', '0:a', '-map', '1:v',
            '-c:a', 'libmp3lame', '-c:v', 'copy',
            '-id3v2_version', '3',
            '-disposition:v:0', 'attached_pic',
            $source,
        ], timeout: 60);

        $path = (new Encoder())->grab($source, $this->dir.'/art.jpg');

        $this->assertWritten($path);
    }

    public function testGrabbingFromSilenceIsAFailure(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('no image to grab');

        (new Encoder())->grab(self::audio(), $this->dir.'/nothing.jpg');
    }

    public function testTilesTheWholeTimeline(): void
    {
        $sheet = (new Encoder())->tile(self::video(), $this->dir);

        $this->assertNotEmpty($sheet->images());
        $this->assertNotEmpty($sheet->cues());

        foreach ($sheet->images() as $image) {
            $this->assertWritten($image);
        }

        $this->assertSame(160, $sheet->width());
        $this->assertSame(120, $sheet->height());
    }

    /**
     * An eight second clip sampled every two seconds is four thumbnails, and
     * they all fit on one five by five sheet.
     */
    public function testCueCountFollowsDurationAndInterval(): void
    {
        $sheet = (new Encoder())->tile(self::video(), $this->dir, (new Tile())->interval(2.0));

        $this->assertCount(1, $sheet->images());
        $this->assertCount(4, $sheet->cues());

        $cues = $sheet->cues();
        $this->assertSame(0.0, $cues[0]->start);
        $this->assertSame(2.0, $cues[0]->end);
        $this->assertSame(2.0, $cues[1]->start);
        $this->assertSame(6.0, $cues[3]->start);
    }

    public function testCuesWalkTheGridLeftToRightThenDown(): void
    {
        $sheet = (new Encoder())->tile(
            self::video(),
            $this->dir,
            (new Tile())->interval(2.0)->grid(2, 2)->width(100),
        );

        $cues = $sheet->cues();

        $this->assertSame([0, 0], [$cues[0]->x, $cues[0]->y]);
        $this->assertSame([100, 0], [$cues[1]->x, $cues[1]->y]);
        $this->assertSame([0, $cues[0]->height], [$cues[2]->x, $cues[2]->y]);
        $this->assertSame([100, $cues[0]->height], [$cues[3]->x, $cues[3]->y]);
    }

    public function testSpillsOntoASecondSheetWhenTheGridFills(): void
    {
        $sheet = (new Encoder())->tile(
            self::video(),
            $this->dir,
            (new Tile())->interval(2.0)->grid(2, 1),
        );

        $this->assertGreaterThanOrEqual(2, \count($sheet->images()));

        $files = \array_unique(\array_map(
            static fn ($cue): string => $cue->file,
            $sheet->cues(),
        ));

        $this->assertGreaterThanOrEqual(2, \count($files));
    }

    public function testWritesAWebvttTimeline(): void
    {
        $sheet = (new Encoder())->tile(self::video(), $this->dir, (new Tile())->interval(2.0));

        $this->assertNotNull($sheet->vtt());
        $this->assertWritten($sheet->vtt());

        $vtt = (string) \file_get_contents((string) $sheet->vtt());

        $this->assertStringStartsWith("WEBVTT\n", $vtt);
        $this->assertStringContainsString('#xywh=', $vtt);
        $this->assertStringContainsString('00:00:00.000 --> 00:00:02.000', $vtt);
    }

    public function testTimelineCanBeSkipped(): void
    {
        $sheet = (new Encoder())->tile(
            self::video(),
            $this->dir,
            (new Tile())->vtt(false),
        );

        $this->assertNull($sheet->vtt());
        $this->assertSame([], \glob($this->dir.'/*.vtt') ?: []);
        $this->assertNotEmpty($sheet->cues());
    }

    public function testTilingSilenceIsAFailure(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('no video to tile');

        (new Encoder())->tile(self::audio(), $this->dir);
    }
}
