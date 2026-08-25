<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Parser\M3u8;
use Utopia\Video\Track;

class ParserM3u8Test extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-m3u8-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir.'/*') ?: [] as $file) {
            \unlink($file);
        }

        @\rmdir($this->dir);
    }

    private function write(string $name, string $body): string
    {
        $path = $this->dir.'/'.$name;
        \file_put_contents($path, $body);

        return $path;
    }

    public function testReadsAttributesWithCommasInsideQuotes(): void
    {
        $attributes = M3u8::attributes(
            '#EXT-X-STREAM-INF:BANDWIDTH=2700000,CODECS="avc1.64001f,mp4a.40.2",RESOLUTION=1280x720',
        );

        $this->assertSame('2700000', $attributes['BANDWIDTH']);
        $this->assertSame('avc1.64001f,mp4a.40.2', $attributes['CODECS']);
        $this->assertSame('1280x720', $attributes['RESOLUTION']);
    }

    public function testReadsVariantsFromAMaster(): void
    {
        $master = $this->write('master.m3u8', <<<'PLAYLIST'
            #EXTM3U
            #EXT-X-VERSION:7
            #EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="English",LANGUAGE="eng",DEFAULT=YES,URI="audio.m3u8"
            #EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360,CODECS="avc1.64001f",AUDIO="audio"
            v360.m3u8
            #EXT-X-STREAM-INF:BANDWIDTH=2538000,RESOLUTION=1280x720,CODECS="avc1.64001f",AUDIO="audio"
            v720.m3u8
            PLAYLIST);

        $variants = M3u8::master($master);

        $this->assertCount(3, $variants);
        $this->assertSame(Track::AUDIO, $variants[0]['type']);
        $this->assertSame('eng', $variants[0]['language']);
        $this->assertSame(Track::VIDEO, $variants[1]['type']);
        $this->assertSame(640, $variants[1]['width']);
        $this->assertSame(360, $variants[1]['height']);
        $this->assertSame(2538000, $variants[2]['bandwidth']);
        $this->assertSame('v720.m3u8', $variants[2]['file']);
    }

    public function testReadsSegmentsFromATransportStreamPlaylist(): void
    {
        $playlist = $this->write('v720.m3u8', <<<'PLAYLIST'
            #EXTM3U
            #EXT-X-VERSION:3
            #EXT-X-TARGETDURATION:6
            #EXTINF:6.000000,
            v720_0000.ts
            #EXTINF:4.500000,
            v720_0001.ts
            #EXT-X-ENDLIST
            PLAYLIST);

        $media = M3u8::media($playlist);

        $this->assertSame(6.0, $media['target']);
        $this->assertSame(3, $media['version']);
        $this->assertCount(2, $media['segments']);
        $this->assertSame('v720_0000.ts', $media['segments'][0]['file']);
        $this->assertSame(6.0, $media['segments'][0]['duration']);
        $this->assertFalse($media['segments'][0]['init']);
        $this->assertSame(4.5, $media['segments'][1]['duration']);
    }

    public function testInitialisationSegmentIsRecognised(): void
    {
        $playlist = $this->write('v720.m3u8', <<<'PLAYLIST'
            #EXTM3U
            #EXT-X-VERSION:7
            #EXT-X-TARGETDURATION:6
            #EXT-X-MAP:URI="v720_init.mp4"
            #EXTINF:6.000000,
            v720_0000.m4s
            PLAYLIST);

        $media = M3u8::media($playlist);

        $this->assertTrue($media['segments'][0]['init']);
        $this->assertSame('v720_init.mp4', $media['segments'][0]['file']);
        $this->assertSame(0.0, $media['segments'][0]['duration']);
        $this->assertFalse($media['segments'][1]['init']);
    }

    public function testReadsAWholePackage(): void
    {
        $this->write('v720_init.mp4', 'init');
        $this->write('v720_0000.m4s', 'aaaa');
        $this->write('v720_0001.m4s', 'bb');

        $this->write('v720.m3u8', <<<'PLAYLIST'
            #EXTM3U
            #EXT-X-VERSION:7
            #EXT-X-TARGETDURATION:6
            #EXT-X-MAP:URI="v720_init.mp4"
            #EXTINF:6.000000,
            v720_0000.m4s
            #EXTINF:2.000000,
            v720_0001.m4s
            PLAYLIST);

        $master = $this->write('master.m3u8', <<<'PLAYLIST'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=2538000,RESOLUTION=1280x720
            v720.m3u8
            PLAYLIST);

        $read = M3u8::read($master, $this->dir);

        $this->assertCount(1, $read['variants']);
        $this->assertSame(6.0, $read['metadata']['targetDuration']);

        $variant = $read['variants'][0];
        $this->assertSame(1280, $variant->width);
        $this->assertSame('1280x720', $variant->resolution());
        $this->assertCount(3, $variant->segments);

        $this->assertTrue($variant->segments[0]->init);
        $this->assertSame(4, $variant->segments[0]->size);
        $this->assertSame(0, $variant->segments[1]->number);
        $this->assertSame(1, $variant->segments[2]->number);
        $this->assertSame(2, $variant->segments[2]->size);
    }

    /**
     * A playlist that names a file which is not there means the run was cut
     * short, and reporting success on a partial package would be worse than
     * failing.
     */
    public function testMissingSegmentIsAFailure(): void
    {
        $this->write('v720.m3u8', "#EXTM3U\n#EXTINF:6.000000,\nmissing.ts\n");
        $master = $this->write('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nv720.m3u8\n");

        $this->expectException(Runtime::class);
        $this->expectExceptionMessage('missing.ts');

        M3u8::read($master, $this->dir);
    }

    public function testMissingPlaylistIsAFailure(): void
    {
        $master = $this->write('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\ngone.m3u8\n");

        $this->expectException(Runtime::class);
        $this->expectExceptionMessage('gone.m3u8');

        M3u8::read($master, $this->dir);
    }

    public function testUnreadablePlaylistIsAFailure(): void
    {
        $this->expectException(Runtime::class);

        M3u8::media($this->dir.'/nothing.m3u8');
    }

    public function testWindowsLineEndingsAreHandled(): void
    {
        $playlist = $this->write(
            'v.m3u8',
            "#EXTM3U\r\n#EXT-X-TARGETDURATION:6\r\n#EXTINF:6.000000,\r\nv_0000.ts\r\n",
        );

        $media = M3u8::media($playlist);

        $this->assertSame(6.0, $media['target']);
        $this->assertSame('v_0000.ts', $media['segments'][0]['file']);
    }
}
