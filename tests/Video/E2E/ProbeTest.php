<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Exception\Input;
use Utopia\Video\Process;
use Utopia\Video\Encoder;
use Utopia\Video\Packager;
use Utopia\Video\Track;

class ProbeTest extends Base
{
    public function testReadsARealFile(): void
    {
        $info = (new Encoder())->probe(self::video());

        $this->assertEqualsWithDelta(8.0, $info->duration, 0.5);
        $this->assertSame(8000, (int) \round($info->milliseconds(), -3));
        $this->assertTrue($info->hasVideo);
        $this->assertTrue($info->hasAudio);
        $this->assertSame(640, $info->width);
        $this->assertSame(480, $info->height);
        $this->assertSame('h264', $info->videoCodec);
        $this->assertSame('aac', $info->audioCodec);
        $this->assertSame(25.0, $info->fps);
        $this->assertSame('4:3', $info->ratio());
        $this->assertNotEmpty($info->raw);
    }

    public function testReadsContainerTags(): void
    {
        $info = (new Encoder())->probe(self::video());

        $this->assertSame('Utopia Test', $info->tags['title'] ?? null);
    }

    public function testReadsEveryTrack(): void
    {
        $info = (new Encoder())->probe(self::video());

        $this->assertCount(2, $info->tracks);
        $this->assertCount(1, $info->tracks(Track::VIDEO));
        $this->assertCount(1, $info->tracks(Track::AUDIO));
    }

    public function testReadsASilentSource(): void
    {
        $info = (new Encoder())->probe(self::silent());

        $this->assertTrue($info->hasVideo);
        $this->assertFalse($info->hasAudio);
        $this->assertNull($info->audioCodec);
    }

    public function testReadsAnAudioOnlySource(): void
    {
        $info = (new Encoder())->probe(self::audio());

        $this->assertFalse($info->hasVideo);
        $this->assertTrue($info->hasAudio);
        $this->assertNull($info->width);
        $this->assertNotNull($info->sampleRate);
    }

    public function testReadsChapters(): void
    {
        $source = $this->dir.'/chapters.mp4';
        $metadata = $this->dir.'/chapters.txt';

        \file_put_contents($metadata, <<<'META'
            ;FFMETADATA1
            [CHAPTER]
            TIMEBASE=1/1000
            START=0
            END=4000
            title=Opening

            [CHAPTER]
            TIMEBASE=1/1000
            START=4000
            END=8000
            title=Closing
            META);

        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', self::video(), '-i', $metadata,
            '-map_metadata', '1', '-c', 'copy', $source,
        ], timeout: 120);

        $info = (new Encoder())->probe($source);

        $this->assertCount(2, $info->chapters);
        $this->assertSame('Opening', $info->chapters[0]->title);
        $this->assertEqualsWithDelta(4.0, $info->chapters[0]->end, 0.1);
        $this->assertSame('Closing', $info->chapters[1]->title);
    }

    public function testAcceptsAUsableSource(): void
    {
        $this->assertTrue((new Encoder())->valid(self::video()));
    }

    public function testRejectsSomethingThatIsNotMedia(): void
    {
        $path = $this->dir.'/notes.txt';
        \file_put_contents($path, 'this is not a video');

        $this->assertFalse((new Encoder())->valid($path));
    }

    public function testRejectsAMissingFile(): void
    {
        $this->assertFalse((new Encoder())->valid($this->dir.'/nothing.mp4'));
    }

    public function testProbingAMissingFileIsAFailure(): void
    {
        $this->expectException(Input::class);

        (new Encoder())->probe($this->dir.'/nothing.mp4');
    }

    /**
     * A Packager can gate its own input, so a caller holding only one of the two
     * facades does not need the other just to check a file.
     */
    public function testEitherFacadeReadsTheSameSource(): void
    {
        $info = (new Packager())->probe(self::video());

        $this->assertSame((new Encoder())->probe(self::video())->duration, $info->duration);
        $this->assertTrue((new Packager())->valid(self::video()));
    }
}
