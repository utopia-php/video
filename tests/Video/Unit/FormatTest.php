<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Format\Copy;
use Utopia\Video\Format\HEVC;
use Utopia\Video\Format\VP9;
use Utopia\Video\Format\X264;
use Utopia\Video\Output;

class FormatTest extends TestCase
{
    /**
     * @testdox X264 defaults to h264 with AAC audio
     */
    public function testX264Defaults(): void
    {
        $args = (new X264())->build();

        $this->assertSame(['-c:v', 'libx264', '-c:a', 'aac'], \array_slice($args, 0, 4));
        $this->assertContains('-keyint_min', $args);
        $this->assertContains('-g', $args);
    }

    /**
     * @testdox The quality knobs reach the command
     */
    public function testQualityKnobsAppear(): void
    {
        $args = (new X264())->crf(22)->bframes(3)->keyframe(2.0)->build();
        $argv = \implode(' ', $args);

        $this->assertStringContainsString('-crf 22', $argv);
        $this->assertStringContainsString('-bf 3', $argv);
        $this->assertStringContainsString('-force_key_frames expr:gte(t,n_forced*2)', $argv);
    }

    public function testFractionalKeyframeIntervalStaysReadable(): void
    {
        $args = (new X264())->keyframe(1.5)->build();

        $this->assertContains('expr:gte(t,n_forced*1.5)', $args);
    }

    public function testRawParametersAreAppendedLast(): void
    {
        $args = (new X264())->crf(22)->params(['-dn', '-sn'])->build();

        $this->assertSame(['-dn', '-sn'], \array_slice($args, -2));
    }

    public function testAudioOnlyJobDropsVideoArguments(): void
    {
        $args = (new X264())->crf(22)->bframes(3)->build(video: false);

        $this->assertSame(['-c:a', 'aac'], $args);
        $this->assertNotContains('-crf', $args);
        $this->assertNotContains('-c:v', $args);
    }

    public function testVideoOnlyJobDropsAudioCodec(): void
    {
        $args = (new X264())->build(audio: false);

        $this->assertNotContains('-c:a', $args);
        $this->assertContains('-c:v', $args);
    }

    /**
     * @testdox HEVC is tagged hvc1 so Apple players accept it
     */
    public function testHevcTagsForApplePlayback(): void
    {
        $args = (new HEVC())->build();

        $this->assertContains('libx265', $args);
        $this->assertContains('hvc1', $args);
    }

    /**
     * @testdox VP9 can be packaged as DASH but not as HLS or CMAF
     */
    public function testVp9PackagesAsDashOnly(): void
    {
        $format = new VP9();

        $this->assertSame([Output::DASH], $format->supports());
        $this->assertContains('libvpx-vp9', $format->build());
        $this->assertSame('libopus', $format->sound());
    }

    /**
     * @testdox X264 can be packaged as HLS, DASH and CMAF alike
     */
    public function testX264PackagesEverywhere(): void
    {
        $this->assertSame(
            [Output::HLS, Output::DASH, Output::CMAF],
            (new X264())->supports(),
        );
    }

    public function testCopyIgnoresQualityKnobs(): void
    {
        $args = (new Copy())->crf(22)->bframes(3)->keyframe(2.0)->build();

        $this->assertSame(['-c:v', 'copy', '-c:a', 'copy'], $args);
    }

    public function testCodecsCanBeOverridden(): void
    {
        $format = new X264('h264_videotoolbox', 'libfdk_aac');

        $this->assertSame('h264_videotoolbox', $format->codec());
        $this->assertSame('libfdk_aac', $format->sound());
    }
}
