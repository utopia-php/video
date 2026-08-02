<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Cue;
use Utopia\Video\Spritesheet;
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
}
