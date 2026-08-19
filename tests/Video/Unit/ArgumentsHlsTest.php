<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Arguments\Hls as Arguments;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\VP9;
use Utopia\Video\Format\X264;
use Utopia\Video\Info;
use Utopia\Video\Output\Hls;
use Utopia\Video\Representation;

class ArgumentsHlsTest extends TestCase
{
    /**
     * @param  list<array{codec: string, language: string}>  $audioTracks
     */
    private function info(bool $video = true, bool $audio = true, array $audioTracks = []): Info
    {
        return new Info(
            duration: 60.0,
            format: 'mov,mp4',
            hasVideo: $video,
            hasAudio: $audio,
            width: 1920,
            height: 1080,
            audioTracks: $audioTracks,
        );
    }

    /**
     * @param  list<Representation>  $reps
     */
    private function build(Info $info, array $reps, ?Hls $output = null): string
    {
        $arguments = new Arguments(
            $info,
            (new X264())->crf(22)->keyframe(2.0),
            $reps,
            $output ?? new Hls(),
            '/out',
        );

        return \implode(' ', $arguments->build());
    }

    public function testSingleRungCarriesSizingAndCappedBitrate(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538, 128)]);

        $this->assertStringContainsString('-map 0:v:0', $argv);
        $this->assertStringContainsString('-map 0:a:0', $argv);
        $this->assertStringContainsString('-filter:v:0 scale=1280:720', $argv);
        $this->assertStringContainsString('-b:v:0 2538k', $argv);
        $this->assertStringContainsString('-maxrate:v:0 2538k', $argv);
        $this->assertStringContainsString('-bufsize:v:0 5076k', $argv);
        $this->assertStringContainsString('-b:a:0 128k', $argv);
    }

    /**
     * Every rung has to be described by one command; running ffmpeg once per
     * rung would decode the source repeatedly and leave each run overwriting
     * the master playlist the previous one wrote.
     */
    public function testEveryRungLivesInOneCommandAndOneMaster(): void
    {
        $argv = $this->build($this->info(), [
            new Representation(640, 360, 800, 96),
            new Representation(1280, 720, 2538, 128),
        ]);

        $this->assertSame(2, \substr_count($argv, '-map 0:v:0'));
        $this->assertStringContainsString('-filter:v:0 scale=640:360', $argv);
        $this->assertStringContainsString('-filter:v:1 scale=1280:720', $argv);
        $this->assertStringContainsString('-b:v:0 800k', $argv);
        $this->assertStringContainsString('-b:v:1 2538k', $argv);
        $this->assertSame(1, \substr_count($argv, '-master_pl_name'));
        $this->assertSame(1, \substr_count($argv, '-var_stream_map'));
        $this->assertStringContainsString('v:0,name:360p,agroup:audio v:1,name:720p,agroup:audio', $argv);
    }

    public function testOutputPathCarriesTheVariantPlaceholder(): void
    {
        $arguments = new Arguments(
            $this->info(),
            new X264(),
            [new Representation(1280, 720, 2538)],
            (new Hls())->name('video'),
            '/out',
        );

        $this->assertSame('/out/video_%v.m3u8', $arguments->target());
    }

    public function testTransportStreamIsTheDefault(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-hls_segment_type mpegts', $argv);
        $this->assertStringContainsString('-hls_segment_filename /out/stream_%v_%04d.ts', $argv);
        $this->assertStringNotContainsString('-hls_fmp4_init_filename', $argv);
    }

    public function testFragmentedMp4AddsAnInitialisationSegment(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Hls())->segments(Hls::FMP4),
        );

        $this->assertStringContainsString('-hls_segment_type fmp4', $argv);
        $this->assertStringContainsString('-hls_segment_filename /out/stream_%v_%04d.m4s', $argv);
        $this->assertStringContainsString('-hls_fmp4_init_filename stream_%v_init.mp4', $argv);
    }

    public function testSegmentsAreDeclaredIndependent(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-hls_flags independent_segments', $argv);
    }

    public function testVodPlaylistKeepsEverySegment(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-hls_playlist_type vod', $argv);
        $this->assertStringContainsString('-hls_list_size 0', $argv);
    }

    public function testSegmentDurationIsHonoured(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Hls())->segment(6),
        );

        $this->assertStringContainsString('-hls_time 6', $argv);
    }

    public function testLanguageTaggedTracksBecomeSeparateAudioRenditions(): void
    {
        $info = $this->info(audioTracks: [
            ['codec' => 'aac', 'language' => 'eng'],
            ['codec' => 'aac', 'language' => 'spa'],
        ]);

        $argv = $this->build($info, [new Representation(1280, 720, 2538, 128)]);

        $this->assertStringContainsString('-map 0:a:0', $argv);
        $this->assertStringContainsString('-map 0:a:1', $argv);
        $this->assertStringContainsString('a:0,agroup:audio,language:eng,name:audio_0,default:yes', $argv);
        $this->assertStringContainsString('a:1,agroup:audio,language:spa,name:audio_1', $argv);
        $this->assertStringNotContainsString('a:1,agroup:audio,language:spa,name:audio_1,default:yes', $argv);
    }

    public function testUntaggedAudioBecomesOneAnonymousRendition(): void
    {
        $info = $this->info(audioTracks: [['codec' => 'aac', 'language' => 'und']]);

        $argv = $this->build($info, [new Representation(1280, 720, 2538)]);

        $this->assertSame(1, \substr_count($argv, '-map 0:a:'));
        $this->assertStringContainsString('a:0,agroup:audio,name:audio_0,default:yes', $argv);
    }

    /**
     * A language tag is what tells one rendition from another, but a track
     * without one is still a track: a file carrying four untagged dubs has to
     * come out with four, not with the first one alone.
     */
    public function testEveryUntaggedTrackIsStillCarried(): void
    {
        $info = $this->info(audioTracks: [
            ['codec' => 'aac', 'language' => 'und'],
            ['codec' => 'aac', 'language' => 'und'],
            ['codec' => 'aac', 'language' => ''],
        ]);

        $argv = $this->build($info, [new Representation(1280, 720, 2538, 128)]);

        $this->assertSame(3, \substr_count($argv, '-map 0:a:'));
        $this->assertStringContainsString('-map 0:a:0', $argv);
        $this->assertStringContainsString('-map 0:a:1', $argv);
        $this->assertStringContainsString('-map 0:a:2', $argv);
        $this->assertStringContainsString('a:0,agroup:audio,name:audio_0,default:yes', $argv);
        $this->assertStringContainsString('a:1,agroup:audio,name:audio_1', $argv);
        $this->assertStringContainsString('a:2,agroup:audio,name:audio_2', $argv);
        $this->assertStringNotContainsString('language:und', $argv);
    }

    /**
     * @testdox A partly tagged source keeps its untagged tracks too
     */
    public function testTaggedAndUntaggedTracksTravelTogether(): void
    {
        $info = $this->info(audioTracks: [
            ['codec' => 'aac', 'language' => 'eng'],
            ['codec' => 'aac', 'language' => 'und'],
            ['codec' => 'aac', 'language' => 'spa'],
        ]);

        $argv = $this->build($info, [new Representation(1280, 720, 2538, 128)]);

        $this->assertSame(3, \substr_count($argv, '-map 0:a:'));
        $this->assertStringContainsString('a:0,agroup:audio,language:eng,name:audio_0,default:yes', $argv);
        $this->assertStringContainsString('a:1,agroup:audio,name:audio_1', $argv);
        $this->assertStringContainsString('a:2,agroup:audio,language:spa,name:audio_2', $argv);
    }

    /**
     * Segments are cut where a keyframe already is, so a job that never named a
     * cadence takes the segment length rather than leaving the encoder to put
     * keyframes wherever it liked.
     */
    public function testKeyframesFollowTheSegmentLengthWhenNoneWasAskedFor(): void
    {
        $arguments = new Arguments(
            $this->info(),
            new X264(),
            [new Representation(1280, 720, 2538)],
            (new Hls())->segment(4),
            '/out',
        );

        $argv = \implode(' ', $arguments->build());

        $this->assertStringContainsString('-force_key_frames expr:gte(t,n_forced*4)', $argv);
        $this->assertStringContainsString('-hls_time 4', $argv);
    }

    public function testAnExplicitKeyframeIntervalStillWins(): void
    {
        $arguments = new Arguments(
            $this->info(),
            (new X264())->keyframe(2.0),
            [new Representation(1280, 720, 2538)],
            (new Hls())->segment(6),
            '/out',
        );

        $argv = \implode(' ', $arguments->build());

        $this->assertStringContainsString('-force_key_frames expr:gte(t,n_forced*2)', $argv);
        $this->assertStringContainsString('-hls_time 6', $argv);
    }

    /**
     * @testdox Keyframes further apart than a segment are rejected
     */
    public function testKeyframesFurtherApartThanASegmentAreRejected(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('A keyframe every 10s cannot cut 4s segments');

        new Arguments(
            $this->info(),
            (new X264())->keyframe(10.0),
            [new Representation(1280, 720, 2538)],
            (new Hls())->segment(4),
            '/out',
        );
    }

    public function testAudioOnlySourceDropsEveryVideoArgument(): void
    {
        $argv = $this->build(
            $this->info(video: false),
            [new Representation(0, 0, 1, 128)],
        );

        $this->assertStringNotContainsString('-c:v', $argv);
        $this->assertStringNotContainsString('-map 0:v:0', $argv);
        $this->assertStringNotContainsString('-filter:v:0', $argv);
        $this->assertStringNotContainsString('-b:v:0', $argv);
        $this->assertStringNotContainsString('-crf', $argv);
        $this->assertStringContainsString('-b:a:0 128k', $argv);
    }

    public function testSilentSourceDropsEveryAudioArgument(): void
    {
        $argv = $this->build(
            $this->info(audio: false),
            [new Representation(1280, 720, 2538)],
        );

        $this->assertStringNotContainsString('-c:a', $argv);
        $this->assertStringNotContainsString('-map 0:a', $argv);
        $this->assertStringNotContainsString('agroup', $argv);
        $this->assertStringContainsString('v:0,name:720p', $argv);
    }

    public function testBaseUrlIsWrittenIntoPlaylists(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Hls())->url('https://cdn.example/'),
        );

        $this->assertStringContainsString('-hls_base_url https://cdn.example/', $argv);
    }

    public function testRawMuxerParametersAreAppended(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Hls())->params(['-tag:v', 'avc1']),
        );

        $this->assertStringContainsString('-tag:v avc1', $argv);
    }

    public function testCodecThatCannotBePackagedIsRejected(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('HLS cannot carry libvpx-vp9');

        new Arguments(
            $this->info(),
            new VP9(),
            [new Representation(1280, 720, 2538)],
            new Hls(),
            '/out',
        );
    }

    public function testLadderCannotBeEmpty(): void
    {
        $this->expectException(Unsupported::class);

        new Arguments($this->info(), new X264(), [], new Hls(), '/out');
    }
}
