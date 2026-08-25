<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Manifest;
use Utopia\Video\Package;
use Utopia\Video\Segment;
use Utopia\Video\Track;
use Utopia\Video\Variant;

class PackageTest extends TestCase
{
    private function package(): Package
    {
        $video = new Variant(
            id: '0',
            type: Track::VIDEO,
            codecs: 'avc1.64001f',
            bandwidth: 2538000,
            width: 1280,
            height: 720,
            segments: [
                new Segment('0', 'v_init.mp4', '/out/v_init.mp4', 0.0, true, 0, 700),
                new Segment('0', 'v_0.m4s', '/out/v_0.m4s', 6.0, false, 0, 1000),
                new Segment('0', 'v_1.m4s', '/out/v_1.m4s', 4.0, false, 1, 800),
            ],
            playlist: '/out/v.m3u8',
        );

        $audio = new Variant(
            id: '1',
            type: Track::AUDIO,
            bandwidth: 128000,
            segments: [new Segment('1', 'a_0.m4s', '/out/a_0.m4s', 6.0, false, 0, 200)],
        );

        return new Package(
            variants: [$video, $audio],
            manifests: [
                new Manifest(Manifest::DASH, '/out/manifest.mpd', true),
                new Manifest(Manifest::HLS, '/out/master.m3u8', true),
                new Manifest(Manifest::HLS, '/out/v.m3u8'),
            ],
            metadata: ['type' => 'static'],
            duration: 10.0,
        );
    }

    public function testFlattensSegmentsAcrossVariants(): void
    {
        $this->assertCount(4, $this->package()->segments());
    }

    public function testFiltersSegmentsByVariant(): void
    {
        $segments = $this->package()->segments('1');

        $this->assertCount(1, $segments);
        $this->assertSame('a_0.m4s', $segments[0]->file);
    }

    public function testListsEveryArtifactForUpload(): void
    {
        $files = $this->package()->files();

        $this->assertContains('/out/manifest.mpd', $files);
        $this->assertContains('/out/master.m3u8', $files);
        $this->assertContains('/out/v_init.mp4', $files);
        $this->assertContains('/out/a_0.m4s', $files);
        $this->assertCount(7, $files);
    }

    public function testFindsAVariantById(): void
    {
        $this->assertSame(Track::AUDIO, $this->package()->variant('1')?->type);
        $this->assertNull($this->package()->variant('nope'));
    }

    public function testAddsUpSegmentSizes(): void
    {
        $this->assertSame(2700, $this->package()->size());
    }

    public function testCarriesManifestMetadata(): void
    {
        $package = $this->package();

        $this->assertSame('static', $package->metadata()['type']);
        $this->assertSame(10.0, $package->duration());
    }

    public function testKnowsWhichManifestIsTheEntryPoint(): void
    {
        $manifests = $this->package()->manifests();

        $this->assertTrue($manifests[0]->main);
        $this->assertFalse($manifests[2]->main);
        $this->assertSame('manifest.mpd', $manifests[0]->file());
    }

    /**
     * Structured data is the point of the result object; a consumer that builds
     * its own manifests should still get everything it needs.
     */
    public function testSegmentsSurviveWithoutManifests(): void
    {
        $package = new Package(variants: $this->package()->variants());

        $this->assertSame([], $package->manifests());
        $this->assertCount(4, $package->segments());
        $this->assertCount(4, $package->files());
    }

    /**
     * @testdox An empty package reports nothing rather than failing
     */
    public function testEmptyPackage(): void
    {
        $package = new Package();

        $this->assertSame([], $package->variants());
        $this->assertSame([], $package->segments());
        $this->assertSame([], $package->files());
        $this->assertSame(0, $package->size());
    }
}
