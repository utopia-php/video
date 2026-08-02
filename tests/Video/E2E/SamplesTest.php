<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Exception\Input;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Parser\M3u8;
use Utopia\Video\Representation;
use Utopia\Video\Encoder;
use Utopia\Video\Packager;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;
use Utopia\Video\Track;
use Utopia\Tests\Samples;

/**
 * Everything in tests/samples/in, put through the whole library.
 *
 * The point is breadth: a consumer is handed whatever a user uploaded, so the
 * same operations have to work on an AVI from 2009 and an HEVC clip from a
 * phone. Results land in tests/samples/out for inspection.
 */
class SamplesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! Samples::available()) {
            return;
        }

        Samples::build();

        // Everything under out/ belongs to the run that produced it. Clearing the
        // whole tree once per run is what stops a directory written by a test
        // that has since been deleted from sitting there looking like output.
        foreach (\glob(Samples::out().'/*') ?: [] as $path) {
            \is_dir($path) ? self::wipe($path) : \unlink($path);
        }
    }

    protected function setUp(): void
    {
        if (! Samples::available()) {
            $this->markTestSkipped('ffmpeg and ffprobe are required');
        }
    }

    /** @var array<string, true> */
    private array $cleared = [];

    /**
     * A directory under tests/samples/out, emptied the first time it is asked for
     * in a test so a stale run cannot be mistaken for a fresh one — and left
     * alone afterwards, so the same directory can be handed out twice.
     */
    private function out(string $name): string
    {
        $dir = Samples::out().'/'.$name;

        if (! isset($this->cleared[$dir])) {
            self::wipe($dir);
            \mkdir($dir, 0o755, true);
            $this->cleared[$dir] = true;
        }

        return $dir;
    }

    private static function wipe(string $dir): void
    {
        foreach (\glob($dir.'/*') ?: [] as $path) {
            \is_dir($path) ? self::wipe($path) : \unlink($path);
        }

        @\rmdir($dir);
    }

    private function format(): X264
    {
        return (new X264())->crf(32)->keyframe(2.0)->params(['-preset', 'ultrafast']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function everySample(): array
    {
        if (! Samples::available()) {
            return ['skipped' => ['', '']];
        }

        Samples::build();

        $cases = [];

        foreach (Samples::all() as $name => $path) {
            $cases[$name] = [$name, $path];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function everyVideoSample(): array
    {
        if (! Samples::available()) {
            return ['skipped' => ['', '']];
        }

        Samples::build();

        $cases = [];

        foreach (Samples::all() as $name => $path) {
            // Audio-only sources are covered by their own tests.
            if (\str_starts_with($name, 'audio.') || \str_starts_with($name, 'artwork.')) {
                continue;
            }

            $cases[$name] = [$name, $path];
        }

        return $cases;
    }

    /**
     * Whatever the container, the library should be able to say what it is.
     *
     * @dataProvider everySample
     */
    public function testReadsEverySample(string $name, string $path): void
    {
        if ($name === '') {
            $this->markTestSkipped('no samples');
        }

        $info = (new Encoder())->probe($path);

        $this->assertGreaterThan(0, $info->duration, $name.' reported no duration');
        $this->assertTrue($info->hasVideo || $info->hasAudio, $name.' reported no streams');
        $this->assertNotSame('', $info->format, $name.' reported no container format');

        if ($info->hasVideo) {
            $this->assertNotNull($info->width, $name.' has video but no width');
            $this->assertNotNull($info->videoCodec, $name.' has video but no codec');
        }

        $this->assertTrue((new Encoder())->valid($path), $name.' was rejected as unusable');
    }

    /**
     * Extensions lie, so the codec is read from the file rather than the name.
     *
     * @dataProvider everySample
     */
    public function testCodecComesFromTheFileNotTheName(string $name, string $path): void
    {
        if ($name === '') {
            $this->markTestSkipped('no samples');
        }

        $info = (new Encoder())->probe($path);

        // What each sample genuinely contains, which is not deducible from its
        // name: .mp4 might be h264 or hevc, and .m4a has no picture at all.
        $expected = [
            'video.mp4' => 'h264',
            'video.mov' => 'h264',
            'video.mkv' => 'h264',
            'video.ts' => 'h264',
            'video.3gp' => 'h264',
            'video.avi' => 'mpeg4',
            'video.wmv' => 'wmv2',
            'video.flv' => 'flv1',
            'video.webm' => 'vp9',
            'video.ogv' => 'theora',
            'hevc.mp4' => 'hevc',
            'anamorphic.mp4' => 'h264',
            'variable-fps.mp4' => 'h264',
            'rotated.mp4' => 'h264',
            'portrait.mp4' => 'h264',
            'silent.mp4' => 'h264',
            'tiny.mp4' => 'h264',
            'chapters.mp4' => 'h264',
            'multi-audio.mp4' => 'h264',
            'multi-audio.mkv' => 'h264',
            'multi-subtitle.mkv' => 'h264',
            'multi-track.mkv' => 'h264',
            'audio.m4a' => null,
            'audio.mp3' => null,
            'artwork.m4a' => null,
        ];

        $this->assertArrayHasKey($name, $expected, $name.' is not described by this test');
        $this->assertSame($expected[$name], $info->videoCodec, $name);
    }

    /**
     * Packaging normalises whatever came in, so a rung is always delivered at the
     * size and codec asked for.
     *
     * @dataProvider everyVideoSample
     */
    public function testPackagesEverySampleAsHls(string $name, string $path): void
    {
        if ($name === '') {
            $this->markTestSkipped('no samples');
        }

        $package = (new Packager())
            ->open($path)
            ->format($this->format())
            ->add(new Representation(320, 180, 300, 64))
            ->output((new Hls())->segment(2))
            ->pack($this->out('hls/'.\pathinfo($name, PATHINFO_FILENAME).'-'.\pathinfo($name, PATHINFO_EXTENSION)));

        $this->assertNotEmpty($package->segments(), $name.' produced no segments');

        foreach ($package->segments() as $segment) {
            $this->assertFileExists($segment->path, $name);
            $this->assertSame(\filesize($segment->path), $segment->size, $name.' '.$segment->file);
        }
    }

    /**
     * The same breadth for DASH, which is the strictest of the three: it insists
     * every rung share an aspect ratio, and it addresses segments through a
     * manifest rather than a playlist. Running it over the whole container matrix
     * is what proves the MPD describes what was actually written, whatever came
     * in.
     *
     * @dataProvider everyVideoSample
     */
    public function testPackagesEverySampleAsDash(string $name, string $path): void
    {
        if ($name === '') {
            $this->markTestSkipped('no samples');
        }

        $dir = $this->out('dash/'.\pathinfo($name, PATHINFO_FILENAME).'-'.\pathinfo($name, PATHINFO_EXTENSION));

        $package = (new Packager())
            ->open($path)
            ->format($this->format())
            ->add(new Representation(320, 180, 300, 64))
            ->output((new Dash())->template(false)->timeline(false)->segment(2))
            ->pack($dir);

        $this->assertNotEmpty($package->segments(), $name.' produced no segments');

        foreach ($package->segments() as $segment) {
            $this->assertFileExists($segment->path, $name);
            $this->assertSame(\filesize($segment->path), $segment->size, $name.' '.$segment->file);
        }

        // Segments are addressed explicitly, which is the mode a consumer serving
        // its own per-segment URLs depends on.
        $manifest = (string) \file_get_contents($dir.'/manifest.mpd');

        $this->assertStringContainsString('<SegmentURL', $manifest, $name);
        $this->assertStringNotContainsString('<SegmentTemplate', $manifest, $name);
    }

    /**
     * @dataProvider everyVideoSample
     */
    public function testGrabsAStillFromEverySample(string $name, string $path): void
    {
        if ($name === '') {
            $this->markTestSkipped('no samples');
        }

        $target = $this->out('stills').'/'.\pathinfo($name, PATHINFO_FILENAME)
            .'-'.\pathinfo($name, PATHINFO_EXTENSION).'.jpg';

        $poster = (new Encoder())->grab($path, $target, (new Thumb())->width(160));

        $this->assertNotFalse(\getimagesize($poster), $name.' produced an unreadable still');
    }

    /**
     * Several audio tracks, each tagged with a language, become separate
     * renditions a player can offer by name.
     */
    public function testEveryAudioLanguageBecomesItsOwnRendition(): void
    {
        $samples = Samples::matching('multi-audio.');

        $this->assertNotEmpty($samples, 'no multi-audio samples were built');

        foreach ($samples as $name => $path) {
            $info = (new Encoder())->probe($path);

            $languages = \array_column($info->audioTracks, 'language');

            $this->assertGreaterThanOrEqual(3, \count($languages), $name);
            $this->assertContains('eng', $languages, $name);
            $this->assertContains('spa', $languages, $name);
            $this->assertContains('fra', $languages, $name);

            $package = (new Packager())
                ->open($path)
                ->format($this->format())
                ->add(new Representation(320, 180, 300, 64))
                ->output((new Hls())->segment(2))
                ->pack($this->out('multi-audio/'.\pathinfo($name, PATHINFO_EXTENSION)));

            $audio = \array_values(\array_filter(
                $package->variants(),
                static fn ($variant): bool => $variant->type === Track::AUDIO,
            ));

            $this->assertCount(\count($languages), $audio, $name.': one rendition per language');

            $master = (string) \file_get_contents(
                $this->out('multi-audio/'.\pathinfo($name, PATHINFO_EXTENSION)).'/master.m3u8'
            );

            foreach ($languages as $language) {
                $this->assertStringContainsString('LANGUAGE="'.$language.'"', $master, $name);
            }

            // Exactly one of them is the default a player starts with.
            $this->assertSame(1, \substr_count($master, 'DEFAULT=YES'), $name);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyOutputShape(): array
    {
        return ['hls' => ['hls'], 'dash' => ['dash'], 'cmaf' => ['cmaf']];
    }

    private function shape(string $shape): Hls|Dash|Cmaf
    {
        return match ($shape) {
            'hls' => (new Hls())->segment(2),
            'dash' => (new Dash())->template(false)->timeline(false)->segment(2),
            default => (new Cmaf())->segment(2),
        };
    }

    /**
     * Multi-language audio has to survive into every output shape, not only the
     * one it was first made to work in.
     *
     * @dataProvider everyOutputShape
     */
    public function testEveryAudioLanguageSurvivesInto(string $shape): void
    {
        $path = Samples::all()['multi-audio.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-audio.mkv was not built');
        }

        $expected = ['eng', 'spa', 'fra', 'jpn'];

        $package = (new Packager())
            ->open($path)
            ->format($this->format())
            ->add(
                new Representation(320, 180, 300, 64),
                new Representation(640, 360, 800, 96),
            )
            ->output($this->shape($shape))
            ->pack($this->out('multi-audio/'.$shape));

        $audio = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::AUDIO,
        ));

        $this->assertCount(\count($expected), $audio, $shape.': one rendition per language');

        $languages = \array_map(static fn ($variant): ?string => $variant->language, $audio);

        foreach ($expected as $language) {
            $this->assertContains($language, $languages, $shape.' lost '.$language);
        }

        // Both video rungs stay switchable alternatives of one another.
        $video = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::VIDEO,
        ));

        $this->assertCount(2, $video, $shape.': both rungs should survive');

        foreach ($package->segments() as $segment) {
            $this->assertFileExists($segment->path, $shape.' '.$segment->file);
        }
    }

    /**
     * @dataProvider everyOutputShape
     */
    public function testTheManifestNamesEveryAudioLanguageIn(string $shape): void
    {
        $path = Samples::all()['multi-audio.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-audio.mkv was not built');
        }

        $dir = $this->out('multi-audio/manifest-'.$shape);

        (new Packager())
            ->open($path)
            ->format($this->format())
            ->add(new Representation(320, 180, 300, 64))
            ->output($this->shape($shape))
            ->pack($dir);

        $expected = ['eng', 'spa', 'fra', 'jpn'];

        // CMAF writes both descriptions, so both have to name the languages —
        // checking only one of them once hid a master that named none of them.
        if ($shape !== 'dash') {
            $master = (string) \file_get_contents($dir.'/master.m3u8');

            foreach ($expected as $language) {
                $this->assertStringContainsString(
                    'LANGUAGE="'.$language.'"',
                    $master,
                    $shape.': the master does not name '.$language,
                );
            }

            // Every audio rendition is named, not just some of them.
            $this->assertSame(
                \substr_count($master, '#EXT-X-MEDIA:TYPE=AUDIO'),
                \substr_count($master, 'TYPE=AUDIO') > 0
                    ? \preg_match_all('/#EXT-X-MEDIA:TYPE=AUDIO[^\n]*LANGUAGE=/', $master)
                    : 0,
                $shape.': some audio renditions have no language',
            );

            // Exactly one language is the one a player starts with.
            $this->assertSame(
                1,
                \preg_match_all('/#EXT-X-MEDIA:TYPE=AUDIO[^\n]*DEFAULT=YES/', $master),
                $shape,
            );
        }

        if ($shape !== 'hls') {
            $manifest = (string) \file_get_contents($dir.'/manifest.mpd');

            foreach ($expected as $language) {
                $this->assertStringContainsString('lang="'.$language.'"', $manifest, $shape);
            }

            // Each language is its own adaptation set, or a player cannot pick one.
            $this->assertSame(4, \substr_count($manifest, 'contentType="audio"'), $shape);
        }
    }

    /**
     * Subtitles are read but never packaged.
     *
     * Reporting an embedded track and carrying it into a package are different
     * jobs, and only the first belongs here — the application that owns the
     * subtitle files decides what to do with them. A source with three of them
     * must therefore come out of pack() with none, and no stray WebVTT beside
     * the media.
     */
    public function testSubtitlesAreReportedByProbeButNeverPackaged(): void
    {
        $path = Samples::all()['multi-subtitle.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-subtitle.mkv was not built');
        }

        $this->assertCount(
            3,
            (new Encoder())->probe($path)->tracks(Track::SUBTITLE),
            'probe should still report what is in the file',
        );

        $dir = $this->out('multi-subtitle/none');

        $package = (new Packager())
            ->open($path)
            ->format($this->format())
            ->add(new Representation(320, 180, 300, 64))
            ->output((new Hls())->segment(2))
            ->pack($dir);

        foreach ($package->variants() as $variant) {
            $this->assertNotSame(Track::SUBTITLE, $variant->type);
        }

        $this->assertSame([], \glob($dir.'/*.vtt') ?: [], 'no WebVTT should be written');
        $this->assertStringNotContainsString(
            'TYPE=SUBTITLES',
            (string) \file_get_contents($dir.'/master.m3u8'),
        );
    }

    /**
     * The case a real release actually looks like: two rungs and four dubs out
     * of one file, every dub selectable. Its two subtitle languages are the
     * application's business, so none of them appear here.
     *
     * @dataProvider everyOutputShape
     */
    public function testEveryDubSurvivesAlongsideTheLadderIn(string $shape): void
    {
        $path = Samples::all()['multi-track.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-track.mkv was not built');
        }

        $dir = $this->out('multi-track/'.$shape);

        $package = (new Packager())
            ->open($path)
            ->format($this->format())
            ->add(
                new Representation(320, 180, 300, 64),
                new Representation(640, 360, 800, 96),
            )
            ->output($this->shape($shape))
            ->pack($dir);

        $of = static fn (string $type): array => \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === $type,
        ));

        $video = $of(Track::VIDEO);
        $audio = $of(Track::AUDIO);
        $subtitles = $of(Track::SUBTITLE);

        $this->assertCount(2, $video, $shape.': two rungs');
        $this->assertCount(4, $audio, $shape.': four dubs');
        $this->assertCount(0, $subtitles, $shape.': subtitles are not packaged');

        $this->assertSame(
            ['eng', 'spa', 'fra', 'jpn'],
            \array_map(static fn ($variant): ?string => $variant->language, $audio),
            $shape.': dubs, in order',
        );

        // Everything that was produced is on disk and accounted for.
        foreach ($package->files() as $file) {
            $this->assertFileExists($file, $shape);
        }
    }

    /**
     * Encoding produces one rendition of the picture, but there is no reason to
     * discard the other audio along the way — a release with four dubs should
     * still have four afterwards.
     *
     * Whether a container can record which language each one is differs, and
     * that is the container's business rather than the library's: AVI has
     * nowhere to put a per-stream language at all.
     *
     * @return array<string, array{string, bool}>
     */
    public static function everyEncodeTarget(): array
    {
        return [
            //            container, keeps language tags
            'mp4' => ['mp4', true],
            'mkv' => ['mkv', true],
            'mov' => ['mov', true],
            'wmv' => ['wmv', true],
            'avi' => ['avi', false],
        ];
    }

    /**
     * @dataProvider everyEncodeTarget
     */
    public function testEncodeKeepsEveryDubIn(string $extension, bool $languages): void
    {
        $path = Samples::all()['multi-track.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-track.mkv was not built');
        }

        $encoder = new Encoder();
        $target = $this->out('encode').'/multi-track.'.$extension;

        $encoder
            ->open($path)
            ->format($this->format())
            ->add(new Representation(320, 180, 300, 64))
            ->encode($target);

        $result = $encoder->probe($target);

        // One rendition of the picture, at the size asked for.
        $this->assertSame(320, $result->width, $extension);
        $this->assertSame(180, $result->height, $extension);

        // Every dub survives, whatever the container can say about it.
        $this->assertCount(4, $result->tracks(Track::AUDIO), $extension.' lost audio tracks');

        if ($languages) {
            $this->assertSame(
                ['eng', 'spa', 'fra', 'jpn'],
                \array_map(
                    // WMV rewrites French as the bibliographic "fre" rather than
                    // the terminological "fra". Both name the same language, and
                    // which one appears is the container's choice, not ours.
                    static fn ($track): ?string => $track->language === 'fre' ? 'fra' : $track->language,
                    $result->tracks(Track::AUDIO),
                ),
                $extension.': dubs, in order',
            );
        }

    }

    /**
     * The staged packaging path builds its intermediates with encode(), so it used
     * to hand the packager a file with one dub and no subtitles no matter what
     * went in.
     */
    public function testStagedIntermediatesKeepEveryDub(): void
    {
        $path = Samples::all()['multi-track.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-track.mkv was not built');
        }

        $encoder = new Encoder();
        $intermediate = $this->out('encode').'/intermediate.mp4';

        // The same call staged() makes, one rung at a time.
        $encoder
            ->open($path)
            ->format($this->format())
            ->add(new Representation(640, 360, 800, 96))
            ->encode($intermediate);

        $this->assertCount(4, $encoder->probe($intermediate)->tracks(Track::AUDIO));
    }

    public function testReadsEverySubtitleLanguage(): void
    {
        $path = Samples::all()['multi-subtitle.mkv'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('multi-subtitle.mkv was not built');
        }

        $subtitles = (new Encoder())->probe($path)->tracks(Track::SUBTITLE);

        $languages = \array_map(
            static fn ($track): ?string => $track->language,
            $subtitles,
        );

        $this->assertCount(3, $subtitles);
        $this->assertContains('eng', $languages);
        $this->assertContains('fra', $languages);
        $this->assertContains('jpn', $languages);
    }

    /**
     * One shared segment set described two ways, on every container we accept.
     */
    public function testCmafSharesOneSegmentSetAcrossContainers(): void
    {
        foreach (['video.mp4', 'video.mkv', 'video.avi', 'video.wmv'] as $name) {
            $path = Samples::all()[$name] ?? null;

            if ($path === null) {
                continue;
            }

            $dir = $this->out('cmaf/'.\pathinfo($name, PATHINFO_EXTENSION));

            $package = (new Packager())
                ->open($path)
                ->format($this->format())
                ->add(
                    new Representation(320, 180, 300, 64),
                    new Representation(640, 360, 800, 96),
                )
                ->output((new Cmaf())->segment(2))
                ->pack($dir);

            $fromManifest = \array_map(
                static fn ($segment): string => $segment->file,
                $package->segments(),
            );

            $fromPlaylists = [];

            foreach (\glob($dir.'/*.m3u8') ?: [] as $playlist) {
                if (\basename($playlist) === 'master.m3u8') {
                    continue;
                }

                foreach (M3u8::media($playlist)['segments'] as $segment) {
                    $fromPlaylists[] = $segment['file'];
                }
            }

            $this->assertNotEmpty($fromPlaylists, $name);

            \sort($fromManifest);
            \sort($fromPlaylists);

            $this->assertSame($fromManifest, $fromPlaylists, $name.': manifests disagree');
        }
    }

    public function testTilesATimelineForEveryContainer(): void
    {
        foreach (['video.mp4', 'video.avi', 'video.wmv', 'video.webm', 'video.flv'] as $name) {
            $path = Samples::all()[$name] ?? null;

            if ($path === null) {
                continue;
            }

            $sheet = (new Encoder())->tile(
                $path,
                $this->out('timelines/'.\pathinfo($name, PATHINFO_EXTENSION)),
                (new Tile())->interval(2.0),
            );

            $this->assertNotEmpty($sheet->images(), $name);
            $this->assertNotEmpty($sheet->cues(), $name);
            $this->assertNotFalse(\getimagesize($sheet->images()[0]), $name);
        }
    }

    public function testAudioOnlySamplesHaveNoPictureToGrab(): void
    {
        $path = Samples::all()['audio.m4a'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('audio.m4a was not built');
        }

        $this->expectException(Input::class);
        $this->expectExceptionMessage('no image to grab');

        (new Encoder())->grab($path, $this->out('stills').'/nothing.jpg');
    }

    public function testArtworkIsFoundInASoundFile(): void
    {
        $path = Samples::all()['artwork.m4a'] ?? null;

        if ($path === null) {
            $this->markTestSkipped('artwork.m4a was not built');
        }

        $encoder = new Encoder();
        $info = $encoder->probe($path);

        $this->assertFalse($info->hasVideo);
        $this->assertNotNull($info->cover);

        $grabbed = $encoder->grab($path, $this->out('stills').'/artwork.jpg');
        $this->assertNotFalse(\getimagesize($grabbed));
    }

}
