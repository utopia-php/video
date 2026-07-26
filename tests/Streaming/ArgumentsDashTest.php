<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Arguments\Dash as DashArguments;
use Utopia\Streaming\Format\X264;
use Utopia\Streaming\Output\Dash;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Representations;

class ArgumentsDashTest extends TestCase
{
    public function testMultiRepDashArgv(): void
    {
        $reps = new Representations([
            (new Representation())->setResize(640, 360)->setKiloBitrate(276)->setAudioKiloBitrate(128),
            (new Representation())->setResize(1280, 720)->setKiloBitrate(2048)->setAudioKiloBitrate(128),
            (new Representation())->setResize(1920, 1080)->setKiloBitrate(4096)->setAudioKiloBitrate(192),
        ]);

        $output = (new Dash())
            ->setHasVideo(true)
            ->setSegmentDuration(4)
            ->setUseTimeline(1)
            ->setUseTemplate(1)
            ->setInitSegmentName(true)
            ->setMediaSegmentName(true);

        $args = new DashArguments('/tmp/pack/video.mp4', new X264(), $output, $reps);
        $joined = implode(' ', $args->build());

        $this->assertStringContainsString('-f dash', $joined);
        $this->assertStringContainsString('-seg_duration 4', $joined);
        $this->assertStringContainsString('-use_timeline 1', $joined);
        $this->assertStringContainsString('-use_template 1', $joined);
        $this->assertStringContainsString('-init_seg_name video_init_$RepresentationID$.$ext$', $joined);
        $this->assertStringContainsString('-media_seg_name video_chunk_$RepresentationID$_$Number%05d$.$ext$', $joined);
        $this->assertStringContainsString('-s:v:0 640x360', $joined);
        $this->assertStringContainsString('-b:v:0 276k', $joined);
        $this->assertStringContainsString('-s:v:1 1280x720', $joined);
        $this->assertStringContainsString('-b:v:2 4096k', $joined);
        $this->assertStringContainsString('-b:a:0 128k', $joined);
        $this->assertStringContainsString('-b:a:2 192k', $joined);
        $this->assertSame('/tmp/pack/video.mpd', $args->getOutputPath());
    }

    public function testAudioOnlyOmitsVideoSizing(): void
    {
        $reps = new Representations([
            (new Representation())->setResize(640, 360)->setKiloBitrate(128)->setAudioKiloBitrate(96),
        ]);

        $output = (new Dash())->setHasVideo(false);
        $args = new DashArguments('/tmp/pack/audio.mp4', new X264(), $output, $reps);
        $joined = implode(' ', $args->build());

        $this->assertStringNotContainsString('-s:v:0', $joined);
        $this->assertStringNotContainsString('-b:v:0', $joined);
        $this->assertStringContainsString('-map 0', $joined);
        $this->assertStringContainsString('-b:a:0 96k', $joined);
    }
}
