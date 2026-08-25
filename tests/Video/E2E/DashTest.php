<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Format\VP9;
use Utopia\Video\Format\X264;
use Utopia\Video\Manifest;
use Utopia\Video\Output\Dash;
use Utopia\Video\Package;
use Utopia\Video\Representation;
use Utopia\Video\Packager;
use Utopia\Video\Track;

class DashTest extends Base
{
    private function pack(Dash $output, Representation ...$reps): Package
    {
        return (new Packager())
            ->open(self::video())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(...$reps)
            ->output($output->segment(2))
            ->pack($this->dir);
    }

    public function testPackagesWithTemplatedAddressing(): void
    {
        $package = $this->pack(new Dash(), new Representation(320, 240, 400, 64));

        $this->assertWritten($this->dir.'/manifest.mpd');

        $manifest = (string) \file_get_contents($this->dir.'/manifest.mpd');
        $this->assertStringContainsString('<SegmentTemplate', $manifest);

        $this->assertNotEmpty($package->segments());

        foreach ($package->segments() as $segment) {
            $this->assertWritten($segment->path);
        }
    }

    /**
     * Turning both switches off makes the manifest name every segment. That is
     * the only way a consumer can rewrite each reference to an address of its
     * own, so it needs to keep working exactly as asked.
     */
    public function testPackagesWithEverySegmentListed(): void
    {
        $package = $this->pack(
            (new Dash())->template(false)->timeline(false),
            new Representation(320, 240, 400, 64),
        );

        $manifest = (string) \file_get_contents($this->dir.'/manifest.mpd');

        $this->assertStringContainsString('<SegmentList', $manifest);
        $this->assertStringContainsString('<SegmentURL', $manifest);
        $this->assertStringNotContainsString('<SegmentTemplate', $manifest);

        $this->assertNotEmpty($package->segments());

        foreach ($package->segments() as $segment) {
            $this->assertStringContainsString($segment->file, $manifest);
            $this->assertWritten($segment->path);
        }
    }

    public function testListedAndTemplatedDescribeTheSameSegments(): void
    {
        $listed = $this->pack(
            (new Dash())->template(false)->timeline(false),
            new Representation(320, 240, 400, 64),
        );

        $names = \array_map(
            static fn ($segment): string => $segment->file,
            $listed->segments(),
        );

        self::remove($this->dir);
        \mkdir($this->dir, 0o755, true);

        $templated = $this->pack(new Dash(), new Representation(320, 240, 400, 64));

        $this->assertSame(
            \count($names),
            \count($templated->segments()),
            'both addressing modes should describe the same number of segments',
        );
    }

    public function testAudioGetsItsOwnAdaptationSet(): void
    {
        $package = $this->pack(new Dash(), new Representation(320, 240, 400, 64));

        $types = \array_map(
            static fn ($variant): string => $variant->type,
            $package->variants(),
        );

        $this->assertContains(Track::VIDEO, $types);
        $this->assertContains(Track::AUDIO, $types);
    }

    public function testEveryRungLandsInOneManifest(): void
    {
        $package = $this->pack(
            new Dash(),
            new Representation(320, 240, 300, 64),
            new Representation(640, 480, 800, 96),
        );

        $video = \array_values(\array_filter(
            $package->variants(),
            static fn ($variant): bool => $variant->type === Track::VIDEO,
        ));

        $this->assertCount(2, $video);
        $this->assertCount(1, \glob($this->dir.'/*.mpd') ?: []);
    }

    public function testInitialisationSegmentIsRecognised(): void
    {
        $package = $this->pack(
            (new Dash())->template(false)->timeline(false),
            new Representation(320, 240, 400, 64),
        );

        $init = \array_values(\array_filter(
            $package->segments(),
            static fn ($segment): bool => $segment->init,
        ));

        $this->assertNotEmpty($init);
        $this->assertWritten($init[0]->path);
    }

    public function testCarriesManifestMetadata(): void
    {
        $package = $this->pack(new Dash(), new Representation(320, 240, 400, 64));
        $metadata = $package->metadata();

        $this->assertSame('static', $metadata['type']);
        $this->assertNotEmpty($metadata['profiles']);
        $this->assertIsFloat($metadata['duration']);
        $this->assertEqualsWithDelta(8.0, $metadata['duration'], 1.0);
    }

    public function testReportedSizesMatchTheFilesOnDisk(): void
    {
        $package = $this->pack(new Dash(), new Representation(320, 240, 400, 64));

        foreach ($package->segments() as $segment) {
            $this->assertSame(\filesize($segment->path), $segment->size, $segment->file);
        }
    }

    public function testManifestCanBeDiscarded(): void
    {
        $package = $this->pack(
            (new Dash())->manifests(false),
            new Representation(320, 240, 400, 64),
        );

        $this->assertSame([], $package->manifests());
        $this->assertFileDoesNotExist($this->dir.'/manifest.mpd');
        $this->assertNotEmpty($package->segments());
    }

    public function testManifestIsKeptByDefault(): void
    {
        $package = $this->pack(new Dash(), new Representation(320, 240, 400, 64));
        $manifests = $package->manifests();

        $this->assertCount(1, $manifests);
        $this->assertSame(Manifest::DASH, $manifests[0]->type);
        $this->assertTrue($manifests[0]->main);
    }

    public function testPackagesVp9(): void
    {
        $package = (new Packager())
            ->open(self::video())
            ->format((new VP9())->crf(40)->keyframe(2.0)->params(['-b:v', '0', '-cpu-used', '8']))
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Dash())->segment(2))
            ->pack($this->dir);

        $this->assertWritten($this->dir.'/manifest.mpd');
        $this->assertNotEmpty($package->segments());
    }
}
