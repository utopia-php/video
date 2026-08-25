<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Cue;
use Utopia\Video\Exception\Input;
use Utopia\Video\Spritesheet;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

class SpritesheetTest extends TestCase
{
    public function testFormatsTimestamps(): void
    {
        $this->assertSame('00:00:00.000', Cue::timestamp(0.0));
        $this->assertSame('00:00:06.500', Cue::timestamp(6.5));
        $this->assertSame('00:01:05.000', Cue::timestamp(65.0));
        $this->assertSame('01:01:01.500', Cue::timestamp(3661.5));
    }

    public function testRendersACueAsAnAddressableRectangle(): void
    {
        $cue = new Cue(0.0, 2.0, 'sprite1.jpg', 320, 180, 160, 90);

        $this->assertSame(
            "00:00:00.000 --> 00:00:02.000\nsprite1.jpg#xywh=320,180,160,90",
            $cue->render(),
        );
    }

    /**
     * The sheet a player fetches is rarely at the path it was written to, so
     * the URL has to be replaceable without touching the geometry.
     */
    public function testCueUrlCanBeRewritten(): void
    {
        $cue = new Cue(0.0, 2.0, 'sprite1.jpg', 0, 0, 160, 90);

        $this->assertStringContainsString(
            '/previews/abc123#xywh=0,0,160,90',
            $cue->render('/previews/abc123'),
        );
    }

    public function testRendersAWebvttTimeline(): void
    {
        $sheet = new Spritesheet(
            ['/out/sprite1.jpg'],
            [
                new Cue(0.0, 2.0, 'sprite1.jpg', 0, 0, 160, 90),
                new Cue(2.0, 4.0, 'sprite1.jpg', 160, 0, 160, 90),
            ],
            '/out/sprite.vtt',
            160,
            90,
        );

        $vtt = $sheet->render();

        $this->assertStringStartsWith("WEBVTT\n", $vtt);
        $this->assertStringContainsString('00:00:00.000 --> 00:00:02.000', $vtt);
        $this->assertStringContainsString('sprite1.jpg#xywh=160,0,160,90', $vtt);
        $this->assertSame(160, $sheet->width());
        $this->assertSame(90, $sheet->height());
    }

    public function testTimelineUrlsCanBeRewrittenWholesale(): void
    {
        $sheet = new Spritesheet(
            ['/out/sprite1.jpg'],
            [new Cue(0.0, 2.0, 'sprite1.jpg', 0, 0, 160, 90)],
        );

        $vtt = $sheet->render(static fn (string $file): string => '/preview/'.$file);

        $this->assertStringContainsString('/preview/sprite1.jpg#xywh=0,0,160,90', $vtt);
    }

    public function testListsEveryArtifact(): void
    {
        $withVtt = new Spritesheet(['/out/sprite1.jpg'], [], '/out/sprite.vtt');
        $withoutVtt = new Spritesheet(['/out/sprite1.jpg'], []);

        $this->assertSame(['/out/sprite1.jpg', '/out/sprite.vtt'], $withVtt->files());
        $this->assertSame(['/out/sprite1.jpg'], $withoutVtt->files());
        $this->assertNull($withoutVtt->vtt());
    }

    /**
     * A two minute clip and a two hour film should not produce the same number
     * of sheets, so the sampling interval grows with the source.
     */
    public function testSamplingIntervalGrowsWithDuration(): void
    {
        $tile = new Tile();

        $this->assertSame(2.0, $tile->every(60));
        $this->assertSame(5.0, $tile->every(300));
        $this->assertSame(10.0, $tile->every(1200));
        $this->assertSame(20.0, $tile->every(2400));
        $this->assertSame(30.0, $tile->every(7200));
    }

    public function testSamplingIntervalCanBePinned(): void
    {
        $tile = (new Tile())->interval(4.0);

        $this->assertSame(4.0, $tile->every(60));
        $this->assertSame(4.0, $tile->every(7200));
    }

    public function testTileDefaults(): void
    {
        $tile = new Tile();

        $this->assertSame(160, $tile->size());
        $this->assertSame(5, $tile->columns());
        $this->assertSame(5, $tile->rows());
        $this->assertSame(25, $tile->cells());
        $this->assertSame(3, $tile->scale());
        $this->assertSame('sprite', $tile->base());
        $this->assertTrue($tile->writes());
    }

    public function testTileIsConfigurable(): void
    {
        $tile = (new Tile())->width(200)->grid(4, 3)->quality(5)->name('thumbs')->vtt(false);

        $this->assertSame(200, $tile->size());
        $this->assertSame(12, $tile->cells());
        $this->assertSame(5, $tile->scale());
        $this->assertSame('thumbs', $tile->base());
        $this->assertFalse($tile->writes());
    }

    /**
     * Sheet names become filenames in the directory they were given, so one that
     * leaves it is refused.
     */
    public function testASheetNameThatLeavesTheDirectoryIsRejected(): void
    {
        $this->expectException(Input::class);

        (new Tile())->name('../sheets');
    }

    /**
     * The interval is a divisor. Zero used to reach ffmpeg as a DivisionByZeroError
     * rather than as anything a caller could catch.
     *
     * @dataProvider brokenTiles
     */
    public function testATileSettingThatCannotWorkIsRejected(callable $configure): void
    {
        $this->expectException(Input::class);

        $configure(new Tile());
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function brokenTiles(): array
    {
        return [
            'zero interval' => [static fn (Tile $tile) => $tile->interval(0.0)],
            'negative interval' => [static fn (Tile $tile) => $tile->interval(-1.0)],
            'zero width' => [static fn (Tile $tile) => $tile->width(0)],
            'negative width' => [static fn (Tile $tile) => $tile->width(-160)],
            'empty grid' => [static fn (Tile $tile) => $tile->grid(0, 0)],
            'negative grid' => [static fn (Tile $tile) => $tile->grid(5, -1)],
            'quality below scale' => [static fn (Tile $tile) => $tile->quality(0)],
            'quality above scale' => [static fn (Tile $tile) => $tile->quality(32)],
        ];
    }

    /**
     * An unset interval is how a caller asks for the duration-scaled default,
     * so null stays legal.
     */
    public function testAnUnsetIntervalIsStillAllowed(): void
    {
        $this->assertSame(2.0, (new Tile())->interval(null)->every(8.0));
    }

    /**
     * @dataProvider brokenThumbs
     */
    public function testAThumbSettingThatCannotWorkIsRejected(callable $configure): void
    {
        $this->expectException(Input::class);

        $configure(new Thumb());
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function brokenThumbs(): array
    {
        return [
            'negative time' => [static fn (Thumb $thumb) => $thumb->time(-1.0)],
            'unscalable width' => [static fn (Thumb $thumb) => $thumb->width(1)],
            'negative width' => [static fn (Thumb $thumb) => $thumb->width(-320)],
            'quality below scale' => [static fn (Thumb $thumb) => $thumb->quality(0)],
            'quality above scale' => [static fn (Thumb $thumb) => $thumb->quality(32)],
        ];
    }

    /**
     * Zero width is how a caller asks to keep the source size, so it stays legal.
     */
    public function testAThumbWidthOfZeroKeepsTheSourceSize(): void
    {
        $this->assertSame(0, (new Thumb())->width(0)->size());
    }
}
