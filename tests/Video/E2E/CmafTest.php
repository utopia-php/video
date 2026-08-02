<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\VP9;
use Utopia\Video\Format\X264;
use Utopia\Video\Manifest;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Package;
use Utopia\Video\Parser\M3u8;
use Utopia\Video\Representation;
use Utopia\Video\Packager;

class CmafTest extends Base
{
    private function pack(?Cmaf $output = null, ?Representation $rep = null): Package
    {
        return (new Packager())
            ->open(self::video())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add($rep ?? new Representation(320, 240, 400, 64))
            ->output(($output ?? new Cmaf())->segment(2))
            ->pack($this->dir);
    }

    public function testWritesBothDescriptions(): void
    {
        $package = $this->pack();

        $this->assertWritten($this->dir.'/manifest.mpd');
        $this->assertWritten($this->dir.'/master.m3u8');

        $types = \array_map(
            static fn (Manifest $manifest): string => $manifest->type,
            $package->manifests(),
        );

        $this->assertContains(Manifest::DASH, $types);
        $this->assertContains(Manifest::HLS, $types);
    }

    /**
     * This is the reason CMAF exists: one copy of the media, addressed by two
     * different kinds of player. If the manifests ever drift onto separate
     * segment sets the storage cost doubles and the benefit disappears.
     */
    public function testBothDescriptionsPointAtTheSameSegments(): void
    {
        $package = $this->pack();

        $fromManifest = [];

        foreach ($package->segments() as $segment) {
            $fromManifest[] = $segment->file;
        }

        $fromPlaylists = [];

        foreach (\glob($this->dir.'/*.m3u8') ?: [] as $playlist) {
            if ($playlist === $this->dir.'/master.m3u8') {
                continue;
            }

            foreach (M3u8::media($playlist)['segments'] as $segment) {
                $fromPlaylists[] = $segment['file'];
            }
        }

        $this->assertNotEmpty($fromPlaylists, 'no media playlists were written');

        \sort($fromManifest);
        \sort($fromPlaylists);

        $this->assertSame($fromManifest, $fromPlaylists);
    }

    public function testSegmentsAreFragmentedMp4(): void
    {
        $package = $this->pack();

        foreach ($package->segments() as $segment) {
            $this->assertMatchesRegularExpression('/\.(m4s|mp4)$/', $segment->file);
            $this->assertWritten($segment->path);
        }
    }

    public function testEverySegmentIsNamedInTheManifest(): void
    {
        $package = $this->pack();
        $manifest = (string) \file_get_contents($this->dir.'/manifest.mpd');

        $this->assertStringContainsString('<SegmentList', $manifest);
        $this->assertStringNotContainsString('<SegmentTemplate', $manifest);

        foreach ($package->segments() as $segment) {
            $this->assertStringContainsString($segment->file, $manifest);
        }
    }

    /**
     * The dash muxer never writes this tag, even though forcing keyframes makes
     * it true, so the packager adds it once the run is done.
     */
    public function testMasterDeclaresSegmentsIndependent(): void
    {
        $this->pack();

        $master = (string) \file_get_contents($this->dir.'/master.m3u8');

        $this->assertStringContainsString('#EXT-X-INDEPENDENT-SEGMENTS', $master);
        $this->assertSame(1, \substr_count($master, '#EXT-X-INDEPENDENT-SEGMENTS'));
        $this->assertStringStartsWith('#EXTM3U', $master);
    }

    public function testMasterStaysValidAfterTheTagIsAdded(): void
    {
        $this->pack();

        $variants = M3u8::master($this->dir.'/master.m3u8');

        $this->assertNotEmpty($variants);

        foreach ($variants as $variant) {
            $this->assertFileExists($this->dir.'/'.$variant['file']);
        }
    }

    public function testHandlesALadder(): void
    {
        $package = (new Packager())
            ->open(self::video())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(
                new Representation(320, 240, 300, 64),
                new Representation(640, 480, 800, 96),
            )
            ->output((new Cmaf())->segment(2))
            ->pack($this->dir);

        $this->assertGreaterThanOrEqual(2, \count($package->variants()));
        $this->assertWritten($this->dir.'/manifest.mpd');
        $this->assertWritten($this->dir.'/master.m3u8');
    }

    public function testEveryArtifactIsListedForUpload(): void
    {
        $package = $this->pack();

        foreach ($package->files() as $file) {
            $this->assertWritten($file);
        }

        $this->assertContains($this->dir.'/manifest.mpd', $package->files());
        $this->assertContains($this->dir.'/master.m3u8', $package->files());
    }

    public function testDescriptionsCanBeDiscardedWhileSegmentsRemain(): void
    {
        $package = $this->pack((new Cmaf())->manifests(false));

        $this->assertSame([], $package->manifests());
        $this->assertSame([], \glob($this->dir.'/*.m3u8') ?: []);
        $this->assertSame([], \glob($this->dir.'/*.mpd') ?: []);
        $this->assertNotEmpty($package->segments());

        foreach ($package->segments() as $segment) {
            $this->assertWritten($segment->path);
        }
    }

    public function testMasterCanBeRenamed(): void
    {
        $this->pack((new Cmaf())->master('index.m3u8')->manifest('video.mpd'));

        $this->assertWritten($this->dir.'/index.m3u8');
        $this->assertWritten($this->dir.'/video.mpd');
    }

    /**
     * WebM cannot carry an HLS playlist, so asking for it should fail loudly
     * rather than quietly producing half a package.
     */
    public function testRejectsACodecItCannotDescribeTwice(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('CMAF cannot carry libvpx-vp9');

        (new Packager())
            ->open(self::video())
            ->format(new VP9())
            ->add(new Representation(320, 240, 400, 64))
            ->output(new Cmaf())
            ->pack($this->dir);
    }
}
