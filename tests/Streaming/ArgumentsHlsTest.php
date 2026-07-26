<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Arguments\Hls as HlsArguments;
use Utopia\Streaming\Format\X264;
use Utopia\Streaming\Output\Hls;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Representations;

class ArgumentsHlsTest extends TestCase
{
    public function testMultiRepMultiAudioArgv(): void
    {
        $reps = new Representations([
            (new Representation())->setResize(640, 360)->setKiloBitrate(276)->setAudioKiloBitrate(128),
            (new Representation())->setResize(1280, 720)->setKiloBitrate(2048)->setAudioKiloBitrate(128),
        ]);

        $output = (new Hls())
            ->setHasVideo(true)
            ->setAudioTracks([
                ['codec' => 'aac', 'language' => 'eng'],
                ['codec' => 'aac', 'language' => 'spa'],
            ])
            ->setSegmentDuration(6);

        $args = new HlsArguments('/tmp/pack/video.mp4', new X264(), $output, $reps);
        $argv = $args->build();
        $joined = implode(' ', $argv);

        $this->assertStringContainsString('-var_stream_map a:0,agroup:audio,language:eng,default:yes a:1,agroup:audio,language:spa v:0,agroup:audio', $joined);
        $this->assertStringContainsString('-master_pl_name master.m3u8', $joined);
        $this->assertStringContainsString('-s:v:0 640x360', $joined);
        $this->assertStringContainsString('-b:v:0 276k', $joined);
        $this->assertStringContainsString('-s:v:0 1280x720', $joined);
        $this->assertStringContainsString('-b:v:0 2048k', $joined);
        $this->assertStringContainsString('-map 0:v:0', $joined);
        $this->assertStringContainsString('-map 0:a:0', $joined);
        $this->assertStringContainsString('-map 0:a:1', $joined);
        $this->assertStringContainsString('-hls_time 6', $joined);
        $this->assertStringContainsString('/tmp/pack/video_%v_360p.m3u8', $joined);
        $this->assertSame('/tmp/pack/video_%v_720p.m3u8', $args->getOutputPath());
        $this->assertStringContainsString('_%v_', $args->getOutputPath());
    }

    public function testAudioOnlyOmitsVideoSizing(): void
    {
        $reps = new Representations([
            (new Representation())->setResize(640, 360)->setKiloBitrate(128)->setAudioKiloBitrate(128),
        ]);

        $output = (new Hls())
            ->setHasVideo(false)
            ->setAudioTracks([
                ['codec' => 'aac', 'language' => 'eng'],
            ]);

        $args = new HlsArguments('/tmp/pack/audio.mp4', new X264(), $output, $reps);
        $joined = implode(' ', $args->build());

        $this->assertStringNotContainsString('-s:v:0', $joined);
        $this->assertStringNotContainsString('-b:v:0', $joined);
        $this->assertStringNotContainsString('-c:v', $joined);
        $this->assertStringNotContainsString('-map 0:v:0', $joined);
        $this->assertStringContainsString('-map 0:a:0', $joined);
        $this->assertStringContainsString('-var_stream_map a:0,agroup:audio,language:eng,default:yes', $joined);
        $this->assertSame('/tmp/pack/audio_%v.m3u8', $args->getOutputPath());
    }
}
