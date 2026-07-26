<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Format\X264;
use Utopia\Streaming\Output\Dash;
use Utopia\Streaming\Output\Hls;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Stream;

class PackagingIntegrationTest extends TestCase
{
    private static string $fixture = '';

    private static string $workDir = '';

    public static function setUpBeforeClass(): void
    {
        if (! self::ffmpegAvailable()) {
            return;
        }

        self::$workDir = sys_get_temp_dir().'/utopia_streaming_'.uniqid('', true);
        mkdir(self::$workDir, 0777, true);
        self::$fixture = self::$workDir.'/sample.mp4';

        $cmd = sprintf(
            'ffmpeg -y -f lavfi -i testsrc=size=640x480:rate=15 -f lavfi -i sine=frequency=1000:sample_rate=44100 -t 3 -c:v libx264 -pix_fmt yuv420p -c:a aac %s 2>/dev/null',
            escapeshellarg(self::$fixture)
        );
        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_file(self::$fixture)) {
            self::$fixture = '';
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$workDir !== '' && is_dir(self::$workDir)) {
            self::removeDir(self::$workDir);
        }
    }

    public function testHlsPackagingProducesMasterAndVariants(): void
    {
        if (self::$fixture === '') {
            $this->markTestSkipped('ffmpeg binary or fixture generation unavailable');
        }

        $outDir = self::$workDir.'/hls';
        mkdir($outDir);

        $stream = new Stream(new FFmpeg(['timeout' => 0]));
        $stream
            ->open(self::$fixture)
            ->setFormat(new X264())
            ->addRepresentations([
                (new Representation())->setResize(320, 240)->setKiloBitrate(200)->setAudioKiloBitrate(64),
                (new Representation())->setResize(640, 480)->setKiloBitrate(500)->setAudioKiloBitrate(96),
            ])
            ->setOutput(new Hls())
            ->save($outDir.'/stream.m3u8');

        $master = $outDir.'/master.m3u8';
        $this->assertFileExists($master, 'ffmpeg should write master.m3u8 via master_pl_name');

        $masterContents = (string) file_get_contents($master);
        $this->assertStringContainsString('#EXTM3U', $masterContents);
        $this->assertStringContainsString('#EXT-X-STREAM-INF', $masterContents);

        $playlists = glob($outDir.'/*.m3u8') ?: [];
        $this->assertGreaterThanOrEqual(2, count($playlists), 'expected master + at least one variant playlist');

        $segments = array_merge(
            glob($outDir.'/*.ts') ?: [],
            glob($outDir.'/*.m4s') ?: []
        );
        $this->assertNotEmpty($segments, 'expected media segments');
    }

    public function testDashPackagingProducesMpd(): void
    {
        if (self::$fixture === '') {
            $this->markTestSkipped('ffmpeg binary or fixture generation unavailable');
        }

        $outDir = self::$workDir.'/dash';
        mkdir($outDir);

        $stream = new Stream(new FFmpeg(['timeout' => 0]));
        $stream
            ->open(self::$fixture)
            ->setFormat(new X264())
            ->addRepresentations([
                (new Representation())->setResize(320, 240)->setKiloBitrate(200)->setAudioKiloBitrate(64),
                (new Representation())->setResize(640, 480)->setKiloBitrate(500)->setAudioKiloBitrate(96),
            ])
            ->setOutput(
                (new Dash())
                    ->setUseTimeline(1)
                    ->setUseTemplate(1)
                    ->setInitSegmentName(true)
                    ->setMediaSegmentName(true)
            )
            ->save($outDir.'/stream.mpd');

        $this->assertFileExists($outDir.'/stream.mpd');
        $mpd = (string) file_get_contents($outDir.'/stream.mpd');
        $this->assertStringContainsString('MPD', $mpd);
    }

    private static function ffmpegAvailable(): bool
    {
        exec('ffmpeg -version 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private static function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
