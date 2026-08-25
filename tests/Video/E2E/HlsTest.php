<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Format\X264;
use Utopia\Video\Manifest;
use Utopia\Video\Output\Hls;
use Utopia\Video\Package;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Packager;
use Utopia\Video\Track;

class HlsTest extends Base
{
    private function pack(Hls $output, Representation ...$reps): Package
    {
        return (new Packager())
            ->open(self::video())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(...$reps)
            ->output($output->segment(2))
            ->pack($this->dir);
    }

    public function testPackagesTransportStreamSegments(): void
    {
        $package = $this->pack(new Hls(), new Representation(320, 240, 400, 64));

        $this->assertWritten($this->dir.'/master.m3u8');
        $this->assertNotEmpty($package->segments());

        foreach ($package->segments() as $segment) {
            $this->assertWritten($segment->path);
            $this->assertStringEndsWith('.ts', $segment->file);
            $this->assertGreaterThan(0, $segment->size);
        }
    }

    /**
     * The whole point of a ladder is that one master describes all of it. An
     * earlier design ran ffmpeg once per rung, and each run overwrote the
     * master the previous one had just written.
     */
    public function testOneMasterDescribesEveryRung(): void
    {
        $package = $this->pack(
            new Hls(),
            new Representation(320, 240, 300, 64),
            new Representation(640, 480, 800, 96),
        );

        $master = (string) \file_get_contents($this->dir.'/master.m3u8');

        $this->assertSame(2, \substr_count($master, '#EXT-X-STREAM-INF'));
        $this->assertStringContainsString('RESOLUTION=320x240', $master);
        $this->assertStringContainsString('RESOLUTION=640x480', $master);

        $video = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::VIDEO,
        ));

        $this->assertCount(2, $video);
        $this->assertSame([240, 480], [$video[0]->height, $video[1]->height]);
    }

    /**
     * A segment can only start on a keyframe, so a job that never named a
     * cadence has to take the segment length as one. Left to itself the encoder
     * places keyframes on its own schedule — every 250 frames by default, which
     * is longer than this whole fixture — so this eight second source would come
     * back as one long segment however short a segment was asked for.
     */
    public function testDefaultsAloneStillCutSegmentsAtTheLengthAskedFor(): void
    {
        $package = (new Packager())
            ->open(self::video())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segment(3))
            ->pack($this->dir);

        $video = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::VIDEO,
        ));

        $this->assertCount(1, $video);
        $this->assertGreaterThan(
            1,
            \count($video[0]->segments),
            'the source was not cut at all, so the keyframes were never forced',
        );

        foreach ($video[0]->segments as $segment) {
            $this->assertLessThan(
                4.5,
                $segment->duration,
                'segment '.$segment->file.' ran past the length that was asked for',
            );
        }
    }

    public function testEachRungIsEncodedAtItsOwnSize(): void
    {
        $package = $this->pack(
            new Hls(),
            new Representation(320, 240, 300, 64),
            new Representation(640, 480, 800, 96),
        );

        foreach ($package->variants() as $variant) {
            if ($variant->type !== Track::VIDEO) {
                continue;
            }

            $this->assertNotEmpty($variant->segments, 'rung '.$variant->id.' produced nothing');
            $this->assertGreaterThan(0, $variant->bandwidth);
        }
    }

    public function testPackagesFragmentedMp4WithAnInitialisationSegment(): void
    {
        $package = $this->pack(
            (new Hls())->segments(Hls::FMP4),
            new Representation(320, 240, 400, 64),
        );

        $init = \array_values(\array_filter(
            $package->segments(),
            static fn ($segment): bool => $segment->init,
        ));

        $this->assertNotEmpty($init, 'no initialisation segment was produced');
        $this->assertWritten($init[0]->path);
        $this->assertSame(0.0, $init[0]->duration);

        $playlist = (string) \file_get_contents((string) $package->variants()[0]->playlist);
        $this->assertStringContainsString('#EXT-X-MAP:URI=', $playlist);
        $this->assertStringContainsString('#EXT-X-VERSION:7', $playlist);

        foreach ($package->segments() as $segment) {
            if (! $segment->init) {
                $this->assertStringEndsWith('.m4s', $segment->file);
            }
        }
    }

    public function testSegmentsAreMarkedIndependent(): void
    {
        $this->pack(new Hls(), new Representation(320, 240, 400, 64));

        $master = (string) \file_get_contents($this->dir.'/master.m3u8');

        $this->assertStringContainsString('#EXT-X-INDEPENDENT-SEGMENTS', $master);
    }

    public function testSegmentDurationsAddUpToTheSource(): void
    {
        $package = $this->pack(new Hls(), new Representation(320, 240, 400, 64));

        $total = 0.0;

        foreach ($package->segments($package->variants()[0]->id) as $segment) {
            $total += $segment->duration;
        }

        $this->assertEqualsWithDelta(8.0, $total, 1.0);
    }

    public function testReportedSizesMatchTheFilesOnDisk(): void
    {
        $package = $this->pack(new Hls(), new Representation(320, 240, 400, 64));

        foreach ($package->segments() as $segment) {
            $this->assertSame(\filesize($segment->path), $segment->size, $segment->file);
        }

        $this->assertGreaterThan(0, $package->size());
    }

    public function testEveryArtifactIsListedForUpload(): void
    {
        $package = $this->pack(new Hls(), new Representation(320, 240, 400, 64));

        foreach ($package->files() as $file) {
            $this->assertWritten($file);
        }

        $this->assertContains($this->dir.'/master.m3u8', $package->files());
    }

    /**
     * A consumer that serves its own manifests should be able to keep the
     * media and discard the playlists, without losing the structured data.
     */
    public function testPlaylistsCanBeDiscardedWhileSegmentsRemain(): void
    {
        $package = $this->pack(
            (new Hls())->manifests(false),
            new Representation(320, 240, 400, 64),
        );

        $this->assertSame([], $package->manifests());
        $this->assertSame([], \glob($this->dir.'/*.m3u8') ?: []);
        $this->assertNotEmpty($package->segments());

        foreach ($package->segments() as $segment) {
            $this->assertWritten($segment->path);
        }
    }

    public function testManifestsAreKeptByDefault(): void
    {
        $package = $this->pack(new Hls(), new Representation(320, 240, 400, 64));

        $manifests = $package->manifests();
        $this->assertNotEmpty($manifests);

        $main = \array_values(\array_filter($manifests, static fn (Manifest $m): bool => $m->main));
        $this->assertCount(1, $main);
        $this->assertSame('master.m3u8', $main[0]->file());
        $this->assertSame(Manifest::HLS, $main[0]->type);
    }

    public function testProgressClimbsToCompletion(): void
    {
        $seen = [];

        (new Packager())
            ->open(self::video())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segment(2))
            ->on(Packager::PROGRESS, function (Progress $progress) use (&$seen): void {
                $seen[] = $progress->percent;
            })
            ->pack($this->dir);

        $this->assertNotEmpty($seen);
        $this->assertSame(100.0, \end($seen));

        $sorted = $seen;
        \sort($sorted);
        $this->assertSame($sorted, $seen, 'progress should never go backwards');
    }

    public function testPackagesASilentSource(): void
    {
        $package = (new Packager())
            ->open(self::silent())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400))
            ->output((new Hls())->segment(2))
            ->pack($this->dir);

        $this->assertNotEmpty($package->segments());

        foreach ($package->variants() as $variant) {
            $this->assertSame(Track::VIDEO, $variant->type);
        }
    }

    public function testNamingFlowsThroughToArtifacts(): void
    {
        $package = $this->pack(
            (new Hls())->name('movie')->master('index.m3u8'),
            new Representation(320, 240, 400, 64),
        );

        $this->assertWritten($this->dir.'/index.m3u8');

        foreach ($package->segments() as $segment) {
            $this->assertStringStartsWith('movie_', $segment->file);
        }
    }
}
