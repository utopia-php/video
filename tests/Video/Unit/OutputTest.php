<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Output;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Thumb;

class OutputTest extends TestCase
{
    /**
     * @testdox Every output shares the same defaults
     */
    public function testSharedDefaults(): void
    {
        foreach ([new Hls(), new Dash(), new Cmaf()] as $output) {
            $this->assertSame(6.0, $output->duration());
            $this->assertTrue($output->keeps());
            $this->assertSame('stream', $output->base());
            $this->assertSame([], $output->extra());
        }
    }

    /**
     * @testdox Each output reports which kind of stream it is
     */
    public function testTypes(): void
    {
        $this->assertSame(Output::HLS, (new Hls())->type());
        $this->assertSame(Output::DASH, (new Dash())->type());
        $this->assertSame(Output::CMAF, (new Cmaf())->type());
    }

    public function testHlsDefaultsToTransportStream(): void
    {
        $hls = new Hls();

        $this->assertFalse($hls->fragmented());
        $this->assertSame('ts', $hls->extension());
        $this->assertSame('master.m3u8', $hls->masterFile());
        $this->assertSame(['independent_segments'], $hls->hlsFlags());
    }

    /**
     * @testdox HLS in fMP4 mode asks for an init segment
     */
    public function testHlsFragmentedMp4(): void
    {
        $hls = (new Hls())->segments(Hls::FMP4);

        $this->assertTrue($hls->fragmented());
        $this->assertSame('m4s', $hls->extension());
    }

    public function testDashDefaultsToTemplatedAddressing(): void
    {
        $dash = new Dash();

        $this->assertTrue($dash->templated());
        $this->assertTrue($dash->timelined());
        $this->assertFalse($dash->listed());
        $this->assertSame('manifest.mpd', $dash->manifestFile());
    }

    public function testDashListsSegmentsOnlyWhenBothSwitchesAreOff(): void
    {
        $this->assertFalse((new Dash())->template(false)->listed());
        $this->assertFalse((new Dash())->timeline(false)->listed());
        $this->assertTrue((new Dash())->template(false)->timeline(false)->listed());
    }

    /**
     * Both manifests have to describe the same segments, so CMAF starts from
     * explicit addressing rather than a formula.
     */
    public function testCmafListsSegmentsByDefault(): void
    {
        $cmaf = new Cmaf();

        $this->assertTrue($cmaf->listed());
        $this->assertSame('master.m3u8', $cmaf->masterFile());
        $this->assertSame('manifest.mpd', $cmaf->manifestFile());
    }

    public function testNamingFlowsIntoSegmentPatterns(): void
    {
        $dash = (new Dash())->name('video');

        $this->assertStringStartsWith('video_init_', $dash->initPattern());
        $this->assertStringStartsWith('video_chunk_', $dash->mediaPattern());
    }

    public function testAdaptationSetsFollowTheStreamsPresent(): void
    {
        $dash = new Dash();

        $this->assertSame('id=0,streams=v id=1,streams=a', $dash->adaptations(1, 1));
        $this->assertSame('id=0,streams=v', $dash->adaptations(1, 0));
        $this->assertSame('custom', $dash->sets('custom')->adaptations(1, 1));
    }

    /**
     * Languages are separate choices rather than bitrate alternatives, so each
     * needs its own set — that is also the only place DASH records a language.
     */
    public function testEachAudioTrackBeyondTheFirstGetsItsOwnAdaptationSet(): void
    {
        $dash = new Dash();

        // One video rung, so audio output streams start at 1.
        $this->assertSame(
            'id=0,streams=v id=1,streams=1 id=2,streams=2 id=3,streams=3',
            $dash->adaptations(1, 3),
        );

        // Three rungs push the audio along to streams 3 and 4.
        $this->assertSame(
            'id=0,streams=v id=1,streams=3 id=2,streams=4',
            $dash->adaptations(3, 2),
        );
    }

    public function testAudioOnlyOutputHasNoVideoAdaptationSet(): void
    {
        $this->assertSame('id=0,streams=a', (new Dash())->adaptations(0, 1));
    }

    /**
     * Adapters read configuration but must never write back into it, or the
     * same object could not be reused for a second job.
     */
    public function testConfigurationIsReusable(): void
    {
        $output = (new Hls())->segment(4)->name('shared');

        $this->assertSame(4.0, $output->duration());
        $this->assertSame('shared', $output->base());
        $this->assertSame(4.0, $output->duration());
        $this->assertSame('shared', $output->base());
    }

    public function testManifestsCanBeTurnedOff(): void
    {
        $this->assertFalse((new Hls())->manifests(false)->keeps());
    }

    public function testThumbDefaults(): void
    {
        $thumb = new Thumb();

        $this->assertNull($thumb->at());
        $this->assertSame(320, $thumb->size());
        $this->assertSame(2, $thumb->scale());
    }

    public function testThumbIsConfigurable(): void
    {
        $thumb = (new Thumb())->time(12.5)->width(640)->quality(4);

        $this->assertSame(12.5, $thumb->at());
        $this->assertSame(640, $thumb->size());
        $this->assertSame(4, $thumb->scale());
    }
}
