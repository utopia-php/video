<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Encoder;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Process;

/**
 * A source may be a playlist that names other files. With the demuxers
 * restricted, neither ffprobe nor ffmpeg follows it, while a plain container
 * still reads as before.
 */
class WhitelistTest extends Base
{
    private const FORMATS = ['mov', 'matroska', 'mpegts'];

    private const PROTOCOLS = ['file'];

    private function playlist(): string
    {
        $segment = $this->dir.'/segment.ts';

        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', self::video(), '-c', 'copy', '-t', '2', $segment,
        ], timeout: 60);

        $path = $this->dir.'/list.m3u8';
        \file_put_contents($path, "#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXTINF:2,\n".$segment."\n#EXT-X-ENDLIST\n");

        return $path;
    }

    public function testAnUnrestrictedProbeFollowsAPlaylist(): void
    {
        $this->assertTrue((new FFprobe())->read($this->playlist())->hasVideo);
    }

    public function testARestrictedProbeRefusesAPlaylist(): void
    {
        $probe = new FFprobe(protocols: self::PROTOCOLS, formats: self::FORMATS);

        $this->expectException(Runtime::class);
        $probe->read($this->playlist());
    }

    public function testARestrictedEncoderRefusesAPlaylist(): void
    {
        // The probe is left open so the refusal can only come from ffmpeg.
        $encoder = new Encoder(new FFmpeg(protocols: self::PROTOCOLS, formats: self::FORMATS), new FFprobe());

        $this->expectException(Runtime::class);
        $encoder->grab($this->playlist(), $this->dir.'/poster.jpg');
    }

    public function testAProbeLimitedToOtherProtocolsRefusesALocalFile(): void
    {
        $probe = new FFprobe(protocols: ['https', 'tls', 'tcp']);

        $this->expectException(Runtime::class);
        $probe->read(self::video());
    }

    public function testAnEncoderLimitedToOtherProtocolsRefusesALocalFile(): void
    {
        $encoder = new Encoder(new FFmpeg(protocols: ['https', 'tls', 'tcp']), new FFprobe());

        $this->expectException(Runtime::class);
        $encoder->grab(self::video(), $this->dir.'/poster.jpg');
    }

    public function testARestrictedEncoderStillEncodesAPlainContainer(): void
    {
        // The encode path builds its own command line; it must still run.
        $encoder = new Encoder(
            new FFmpeg(protocols: self::PROTOCOLS, formats: self::FORMATS),
            new FFprobe(protocols: self::PROTOCOLS, formats: self::FORMATS),
        );

        $this->assertWritten($encoder->open(self::video())->encode($this->dir.'/out.mp4'));
    }

    public function testARestrictedEncoderStillReadsAPlainContainer(): void
    {
        $encoder = new Encoder(
            new FFmpeg(protocols: self::PROTOCOLS, formats: self::FORMATS),
            new FFprobe(protocols: self::PROTOCOLS, formats: self::FORMATS),
        );

        $this->assertTrue($encoder->probe(self::video())->hasVideo);
        $this->assertWritten($encoder->grab(self::video(), $this->dir.'/poster.jpg'));
    }
}
