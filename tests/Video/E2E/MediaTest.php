<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Parser\M3u8;
use Utopia\Video\Process;
use Utopia\Video\Representation;
use Utopia\Video\Encoder;
use Utopia\Video\Packager;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;
use Utopia\Video\Track;

/**
 * Real media is not `testsrc`.
 *
 * Files that arrive from cameras, editors and download pipelines have
 * non-square pixels, variable frame rates, rotation flags, several audio tracks,
 * embedded subtitles and chapters. Each fixture here reproduces one of those
 * properties so the library keeps handling it.
 *
 * Fixtures are generated rather than committed, so the suite stays small and the
 * exact shape of each one is described by the code that needs it.
 */
class MediaTest extends TestCase
{
    private static string $root;

    private static bool $available = false;

    private string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$available = Process::exists('ffmpeg') && Process::exists('ffprobe');

        if (! self::$available) {
            return;
        }

        self::$root = \sys_get_temp_dir().'/utopia-streaming-media';

        if (! \is_dir(self::$root)) {
            \mkdir(self::$root, 0o755, true);
        }

        self::base();
    }

    protected function setUp(): void
    {
        if (! self::$available) {
            $this->markTestSkipped('ffmpeg and ffprobe are required');
        }

        $this->dir = self::$root.'/'.\bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            self::remove($this->dir);
        }
    }

    private static function remove(string $dir): void
    {
        foreach (\glob($dir.'/*') ?: [] as $path) {
            \is_dir($path) ? self::remove($path) : \unlink($path);
        }

        @\rmdir($dir);
    }

    /**
     * @param  list<string>  $args
     */
    private static function ffmpeg(array $args, string $target): string
    {
        if (\is_file($target)) {
            return $target;
        }

        Process::run(
            [...['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'], ...$args, $target],
            timeout: 180,
        );

        return $target;
    }

    /**
     * Eight seconds of moving detail with a tone, keyframed every two seconds.
     */
    private static function base(): string
    {
        return self::ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc2=duration=8:size=640x360:rate=30',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=8',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            '-c:a', 'aac', '-shortest',
        ], self::$root.'/base.mp4');
    }

    private static function format(): X264
    {
        return (new X264())->crf(32)->keyframe(2.0)->params(['-preset', 'ultrafast']);
    }

    /**
     * Non-square pixels: stored 4:3, displayed wider.
     */
    public function testReadsAnamorphicPixels(): void
    {
        $path = self::ffmpeg([
            '-i', self::base(),
            '-vf', 'scale=640:480,setsar=4:3',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'copy',
        ], self::$root.'/anamorphic.mp4');

        $info = (new Encoder())->probe($path);

        $this->assertSame(640, $info->width);
        $this->assertSame(480, $info->height);

        // The display ratio comes from the container, not from width/height, so
        // it must not simply be 4:3 here.
        $this->assertNotSame('4:3', $info->ratio());
    }

    /**
     * Packaging normalises pixels, so a rung is delivered at the size requested
     * however odd the source's were.
     */
    public function testPackagesAnamorphicSourceAtTheRequestedSize(): void
    {
        $path = self::ffmpeg([
            '-i', self::base(),
            '-vf', 'scale=640:480,setsar=4:3',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'copy',
        ], self::$root.'/anamorphic.mp4');

        $encoder = new Encoder();

        $encoded = $encoder
            ->open($path)
            ->format(self::format())
            ->add(new Representation(320, 180, 300, 64))
            ->encode($this->dir.'/out.mp4');

        $result = $encoder->probe($encoded);

        $this->assertSame(320, $result->width);
        $this->assertSame(180, $result->height);
    }

    public function testDetectsAVariableFrameRate(): void
    {
        $path = self::ffmpeg([
            '-i', self::base(),
            '-vf', "select='not(mod(n,3))+gte(t,4)'",
            '-fps_mode', 'vfr',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-an',
        ], self::$root.'/vfr.mp4');

        $this->assertSame('Variable', (new Encoder())->probe($path)->fpsMode);
    }

    /**
     * Phone footage carries its orientation in the display matrix rather than in
     * the frame size.
     */
    public function testReadsRotationFromTheDisplayMatrix(): void
    {
        $path = self::$root.'/rotated.mp4';

        if (! \is_file($path)) {
            Process::run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-display_rotation', '90', '-i', self::base(), '-c', 'copy', $path,
            ], timeout: 180);
        }

        $info = (new Encoder())->probe($path);

        $this->assertSame(90, $info->rotation);
        $this->assertSame(640, $info->width, 'rotation must not change the stored size');
    }

    public function testReadsSeveralAudioTracksAndTheirLanguages(): void
    {
        $path = self::ffmpeg([
            '-i', self::base(), '-i', self::base(),
            '-map', '0:v', '-map', '0:a', '-map', '1:a',
            '-metadata:s:a:0', 'language=eng',
            '-metadata:s:a:1', 'language=spa',
            '-c:v', 'copy', '-c:a', 'aac',
        ], self::$root.'/multiaudio.mp4');

        $info = (new Encoder())->probe($path);

        $this->assertCount(2, $info->audioTracks);
        $this->assertSame('eng', $info->audioTracks[0]['language']);
        $this->assertSame('spa', $info->audioTracks[1]['language']);
        $this->assertCount(2, $info->tracks(Track::AUDIO));
    }

    /**
     * Each tagged track becomes its own rendition, so a player can offer the
     * choice.
     */
    public function testPackagesEachAudioLanguageSeparately(): void
    {
        $path = self::ffmpeg([
            '-i', self::base(), '-i', self::base(),
            '-map', '0:v', '-map', '0:a', '-map', '1:a',
            '-metadata:s:a:0', 'language=eng',
            '-metadata:s:a:1', 'language=spa',
            '-c:v', 'copy', '-c:a', 'aac',
        ], self::$root.'/multiaudio.mp4');

        $package = (new Packager())
            ->open($path)
            ->format(self::format())
            ->add(new Representation(320, 180, 300, 64))
            ->output((new Hls())->segment(2))
            ->pack($this->dir);

        $audio = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::AUDIO,
        ));

        $this->assertCount(2, $audio, 'both tagged tracks should be offered');

        $master = (string) \file_get_contents($this->dir.'/master.m3u8');
        $this->assertStringContainsString('LANGUAGE="eng"', $master);
        $this->assertStringContainsString('LANGUAGE="spa"', $master);
    }

    public function testReadsAnEmbeddedSubtitleTrack(): void
    {
        $srt = self::$root.'/embedded.srt';
        \file_put_contents($srt, "1\n00:00:01,000 --> 00:00:03,000\nBonjour\n");

        $path = self::ffmpeg([
            '-i', self::base(), '-i', $srt,
            '-map', '0', '-map', '1',
            '-c:v', 'copy', '-c:a', 'copy', '-c:s', 'mov_text',
            '-metadata:s:s:0', 'language=fra',
        ], self::$root.'/subtitled.mp4');

        $subtitles = (new Encoder())->probe($path)->tracks(Track::SUBTITLE);

        $this->assertCount(1, $subtitles);
        $this->assertSame('fra', $subtitles[0]->language);
    }

    public function testReadsChaptersAndContainerTags(): void
    {
        $meta = self::$root.'/chapters.txt';
        \file_put_contents($meta, <<<'META'
            ;FFMETADATA1
            title=Real World Clip
            artist=Utopia
            [CHAPTER]
            TIMEBASE=1/1000
            START=0
            END=4000
            title=Opening
            META);

        $path = self::ffmpeg([
            '-i', self::base(), '-i', $meta,
            '-map_metadata', '1', '-map', '0', '-c', 'copy',
        ], self::$root.'/chapters.mp4');

        $info = (new Encoder())->probe($path);

        $this->assertNotEmpty($info->chapters);
        $this->assertSame('Opening', $info->chapters[0]->title);
        $this->assertSame('Real World Clip', $info->tags['title'] ?? null);
    }

    /**
     * Music files carry their artwork as a single frame video stream: no video to
     * speak of, but a picture worth having.
     */
    public function testGrabsArtworkFromASoundFile(): void
    {
        $cover = self::ffmpeg(
            ['-i', self::base(), '-frames:v', '1', '-vf', 'scale=300:300'],
            self::$root.'/cover.jpg',
        );

        $audio = self::ffmpeg(['-i', self::base(), '-vn', '-c:a', 'aac'], self::$root.'/audio.m4a');

        $path = self::ffmpeg([
            '-i', $audio, '-i', $cover,
            '-map', '0:a', '-map', '1:v', '-c', 'copy',
            '-disposition:v:0', 'attached_pic',
        ], self::$root.'/artwork.m4a');

        $info = (new Encoder())->probe($path);

        $this->assertFalse($info->hasVideo, 'artwork is not video');
        $this->assertNotNull($info->cover, 'but the picture should be found');

        $grabbed = (new Encoder())->grab($path, $this->dir.'/art.jpg');
        $this->assertNotFalse(\getimagesize($grabbed));
    }

    public function testASoundFileWithNoArtworkHasNothingToGrab(): void
    {
        $audio = self::ffmpeg(['-i', self::base(), '-vn', '-c:a', 'aac'], self::$root.'/audio.m4a');

        $this->expectException(Input::class);
        $this->expectExceptionMessage('no image to grab');

        (new Encoder())->grab($audio, $this->dir.'/none.jpg');
    }

    public function testHandlesPortraitFootage(): void
    {
        $path = self::ffmpeg([
            '-i', self::base(),
            '-vf', 'scale=360:640,setsar=1:1',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'copy',
        ], self::$root.'/portrait.mp4');

        $encoder = new Encoder();
        $info = $encoder->probe($path);

        $this->assertSame(360, $info->width);
        $this->assertSame(640, $info->height);
        $this->assertSame('9:16', $info->ratio());

        $sheet = $encoder->tile($path, $this->dir.'/timeline', (new Tile())->interval(2.0)->width(90));

        $this->assertNotEmpty($sheet->cues());
        $this->assertGreaterThan($sheet->width(), $sheet->height(), 'thumbnails stay portrait');
    }

    public function testHandlesAClipShorterThanOneSegment(): void
    {
        $path = self::ffmpeg(['-i', self::base(), '-t', '0.5', '-c', 'copy'], self::$root.'/tiny.mp4');

        $encoder = new Encoder();

        $this->assertTrue($encoder->valid($path));

        $package = (new Packager())
            ->open($path)
            ->format(self::format())
            ->add(new Representation(320, 180, 300, 64))
            ->output((new Hls())->segment(6))
            ->pack($this->dir);

        $this->assertNotEmpty($package->segments());

        $sheet = $encoder->tile($path, $this->dir.'/timeline');
        $this->assertNotEmpty($sheet->cues());
    }

    /**
     * A DASH adaptation set may only hold rungs that are alternatives of one
     * another, so they have to be the same shape. The muxer says so only once it
     * is already running, and never says which rungs clashed.
     */
    public function testRejectsAMixedShapeLadderForDash(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('share one aspect ratio');

        (new Packager())
            ->open(self::base())
            ->format(self::format())
            ->add(
                new Representation(320, 240, 300, 64),   // 4:3
                new Representation(640, 360, 800, 96),   // 16:9
            )
            ->output((new Dash())->segment(2))
            ->pack($this->dir);
    }

    public function testRejectsAMixedShapeLadderForCmaf(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('share one aspect ratio');

        (new Packager())
            ->open(self::base())
            ->format(self::format())
            ->add(
                new Representation(320, 240, 300, 64),
                new Representation(640, 360, 800, 96),
            )
            ->output((new Cmaf())->segment(2))
            ->pack($this->dir);
    }

    /**
     * HLS has no such rule, so the same ladder is fine there.
     */
    public function testAllowsAMixedShapeLadderForHls(): void
    {
        $package = (new Packager())
            ->open(self::base())
            ->format(self::format())
            ->add(
                new Representation(320, 240, 300, 64),
                new Representation(640, 360, 800, 96),
            )
            ->output((new Hls())->segment(2))
            ->pack($this->dir);

        $video = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::VIDEO,
        ));

        $this->assertCount(2, $video);
    }

    /**
     * The reason CMAF exists: two manifests, one copy of the media.
     */
    public function testCmafManifestsShareOneSegmentSetOnRealFootage(): void
    {
        $package = (new Packager())
            ->open(self::base())
            ->format(self::format())
            ->add(
                new Representation(320, 180, 300, 64),
                new Representation(640, 360, 800, 96),
            )
            ->output((new Cmaf())->segment(2))
            ->pack($this->dir);

        $fromManifest = \array_map(
            static fn ($segment): string => $segment->file,
            $package->segments(),
        );

        $fromPlaylists = [];

        foreach (\glob($this->dir.'/*.m3u8') ?: [] as $playlist) {
            if (\basename($playlist) === 'master.m3u8') {
                continue;
            }

            foreach (M3u8::media($playlist)['segments'] as $segment) {
                $fromPlaylists[] = $segment['file'];
            }
        }

        $this->assertNotEmpty($fromPlaylists);

        \sort($fromManifest);
        \sort($fromPlaylists);

        $this->assertSame($fromManifest, $fromPlaylists);
    }

    public function testGrabsAPosterAtAGivenMomentFromRealFootage(): void
    {
        $poster = (new Encoder())->grab(
            self::base(),
            $this->dir.'/poster.jpg',
            (new Thumb())->time(5.0)->width(240),
        );

        $size = \getimagesize($poster);

        $this->assertNotFalse($size);
        $this->assertSame(240, $size[0]);
    }
}
