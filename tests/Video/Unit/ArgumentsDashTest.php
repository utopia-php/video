<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Arguments\Cmaf as CmafArguments;
use Utopia\Video\Arguments\Dash as Arguments;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\VP9;
use Utopia\Video\Format\X264;
use Utopia\Video\Info;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Representation;

class ArgumentsDashTest extends TestCase
{
    private function info(bool $video = true, bool $audio = true): Info
    {
        return new Info(
            duration: 60.0,
            format: 'mov,mp4',
            hasVideo: $video,
            hasAudio: $audio,
            width: 1920,
            height: 1080,
        );
    }

    /**
     * @param  list<Representation>  $reps
     */
    private function build(Info $info, array $reps, ?Dash $output = null): string
    {
        $arguments = new Arguments(
            $info,
            (new X264())->crf(22)->keyframe(2.0),
            $reps,
            $output ?? new Dash(),
            '/out',
        );

        return \implode(' ', $arguments->build());
    }

    public function testTemplatedAddressingIsTheDefault(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-f dash', $argv);
        $this->assertStringContainsString('-use_template 1', $argv);
        $this->assertStringContainsString('-use_timeline 1', $argv);
    }

    /**
     * Turning both off is what makes the manifest name every segment outright,
     * which is the only way to address them by something other than filename.
     */
    public function testListedAddressingIsRequestedByTurningBothOff(): void
    {
        $output = (new Dash())->template(false)->timeline(false);

        $this->assertTrue($output->listed());

        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)], $output);

        $this->assertStringContainsString('-use_template 0', $argv);
        $this->assertStringContainsString('-use_timeline 0', $argv);
    }

    public function testSegmentNamingPatternsAreDeclared(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Dash())->name('video'),
        );

        $this->assertStringContainsString('-init_seg_name video_init_$RepresentationID$.$ext$', $argv);
        $this->assertStringContainsString(
            '-media_seg_name video_chunk_$RepresentationID$_$Number%05d$.$ext$',
            $argv,
        );
    }

    public function testSegmentNamingCanBeOverridden(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Dash())->init('i-$RepresentationID$.m4s')->media('c-$Number$.m4s'),
        );

        $this->assertStringContainsString('-init_seg_name i-$RepresentationID$.m4s', $argv);
        $this->assertStringContainsString('-media_seg_name c-$Number$.m4s', $argv);
    }

    public function testEveryRungLivesInOneCommand(): void
    {
        $argv = $this->build($this->info(), [
            new Representation(640, 360, 800),
            new Representation(1280, 720, 2538),
        ]);

        $this->assertSame(2, \substr_count($argv, '-map 0:v:0'));
        $this->assertStringContainsString('-filter:v:0 scale=640:360', $argv);
        $this->assertStringContainsString('-filter:v:1 scale=1280:720', $argv);
        $this->assertSame(1, \substr_count($argv, '-f dash'));
    }

    public function testAudioGetsItsOwnAdaptationSet(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-adaptation_sets id=0,streams=v id=1,streams=a', $argv);
    }

    /**
     * Video rungs are alternatives of one another so they share a set, but audio
     * tracks in different languages are separate choices — and a set is the only
     * place DASH can record a language. Grouping them together would present four
     * languages as four bitrates of the first one.
     */
    public function testEachAudioLanguageGetsItsOwnAdaptationSet(): void
    {
        $info = new Info(
            duration: 60.0,
            format: 'matroska',
            hasVideo: true,
            hasAudio: true,
            width: 1920,
            height: 1080,
            audioTracks: [
                ['codec' => 'aac', 'language' => 'eng'],
                ['codec' => 'aac', 'language' => 'spa'],
                ['codec' => 'aac', 'language' => 'fra'],
            ],
        );

        $argv = $this->build($info, [
            new Representation(640, 360, 800),
            new Representation(1280, 720, 2538),
        ]);

        // Two video rungs occupy output streams 0 and 1, so audio starts at 2.
        $this->assertStringContainsString(
            '-adaptation_sets id=0,streams=v id=1,streams=2 id=2,streams=3 id=3,streams=4',
            $argv,
        );
    }

    /**
     * Untagged tracks are alternatives of nothing in particular, but they are
     * still separate tracks, so each needs a set of its own the same way a
     * language does.
     */
    public function testUntaggedTracksAlsoGetOneAdaptationSetEach(): void
    {
        $info = new Info(
            duration: 60.0,
            format: 'matroska',
            hasVideo: true,
            hasAudio: true,
            width: 1920,
            height: 1080,
            audioTracks: [
                ['codec' => 'aac', 'language' => 'und'],
                ['codec' => 'aac', 'language' => 'und'],
            ],
        );

        $argv = $this->build($info, [new Representation(1280, 720, 2538)]);

        $this->assertSame(2, \substr_count($argv, '-map 0:a:'));
        $this->assertStringContainsString('-adaptation_sets id=0,streams=v id=1,streams=1 id=2,streams=2', $argv);
    }

    public function testAdaptationSetsCanStillBeOverridden(): void
    {
        $argv = $this->build(
            $this->info(),
            [new Representation(1280, 720, 2538)],
            (new Dash())->sets('id=0,streams=v,a'),
        );

        $this->assertStringContainsString('-adaptation_sets id=0,streams=v,a', $argv);
    }

    public function testSilentSourceHasNoAudioAdaptationSet(): void
    {
        $argv = $this->build($this->info(audio: false), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-adaptation_sets id=0,streams=v', $argv);
        $this->assertStringNotContainsString('streams=a', $argv);
    }

    public function testWholePresentationStaysInTheManifest(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $this->assertStringContainsString('-window_size 0', $argv);
    }

    /**
     * @testdox The manifest is written where the output says
     */
    public function testManifestPath(): void
    {
        $arguments = new Arguments(
            $this->info(),
            new X264(),
            [new Representation(1280, 720, 2538)],
            (new Dash())->manifest('video.mpd'),
            '/out',
        );

        $this->assertSame('/out/video.mpd', $arguments->target());
    }

    /**
     * @testdox VP9 packages as DASH
     */
    public function testVp9PackagesAsDash(): void
    {
        $argv = $this->build($this->info(), [new Representation(1280, 720, 2538)]);

        $arguments = new Arguments(
            $this->info(),
            new VP9(),
            [new Representation(1280, 720, 2538)],
            new Dash(),
            '/out',
        );

        $this->assertStringContainsString('-f dash', $argv);
        $this->assertContains('libvpx-vp9', $arguments->build());
    }

    public function testCmafAsksTheSameMuxerForBothDescriptions(): void
    {
        $arguments = new CmafArguments(
            $this->info(),
            (new X264())->crf(22)->keyframe(2.0),
            [new Representation(1280, 720, 2538)],
            new Cmaf(),
            '/out',
        );

        $argv = \implode(' ', $arguments->build());

        $this->assertStringContainsString('-f dash', $argv);
        $this->assertStringContainsString('-dash_segment_type mp4', $argv);
        $this->assertStringContainsString('-hls_playlist 1', $argv);
        $this->assertStringContainsString('-hls_master_name master.m3u8', $argv);
        $this->assertSame('/out/manifest.mpd', $arguments->target());
    }

    /**
     * The muxer puts every video rung in one adaptation set and then refuses a
     * set whose members disagree on aspect ratio — but only once the command is
     * already running, and without saying which rungs clashed.
     */
    public function testRejectsALadderThatChangesShape(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('share one aspect ratio');

        new Arguments(
            $this->info(),
            new X264(),
            [
                new Representation(320, 240, 300),   // 4:3
                new Representation(640, 360, 800),   // 16:9
            ],
            new Dash(),
            '/out',
        );
    }

    public function testTheMessageNamesTheRungsThatClash(): void
    {
        try {
            new Arguments(
                $this->info(),
                new X264(),
                [new Representation(320, 240, 300), new Representation(640, 360, 800)],
                new Dash(),
                '/out',
            );
            $this->fail('expected the mismatch to be rejected');
        } catch (Unsupported $exception) {
            $this->assertStringContainsString('4:3', $exception->getMessage());
            $this->assertStringContainsString('240p (320x240)', $exception->getMessage());
            $this->assertStringContainsString('16:9', $exception->getMessage());
            $this->assertStringContainsString('360p (640x360)', $exception->getMessage());
            $this->assertStringContainsString('HLS', $exception->getMessage());
        }
    }

    public function testAcceptsALadderThatKeepsItsShape(): void
    {
        $argv = $this->build($this->info(), [
            new Representation(320, 180, 300),
            new Representation(640, 360, 800),
            new Representation(1280, 720, 2538),
        ]);

        $this->assertStringContainsString('-f dash', $argv);
        $this->assertSame(3, \substr_count($argv, '-map 0:v:0'));
    }

    public function testCmafInheritsTheSameShapeRule(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('share one aspect ratio');

        new CmafArguments(
            $this->info(),
            new X264(),
            [new Representation(320, 240, 300), new Representation(640, 360, 800)],
            new Cmaf(),
            '/out',
        );
    }

    public function testCmafListsEverySegmentByDefault(): void
    {
        $output = new Cmaf();

        $this->assertTrue($output->listed());

        $arguments = new CmafArguments(
            $this->info(),
            new X264(),
            [new Representation(1280, 720, 2538)],
            $output,
            '/out',
        );

        $argv = \implode(' ', $arguments->build());

        $this->assertStringContainsString('-use_template 0', $argv);
        $this->assertStringContainsString('-use_timeline 0', $argv);
    }
}
