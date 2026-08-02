<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * The result of packaging: what was produced, described well enough that a
 * consumer can serve it without ever reading the manifests back.
 */
final class Package
{
    /**
     * @param  list<Variant>  $variants
     * @param  list<Manifest>  $manifests
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private readonly array $variants = [],
        private readonly array $manifests = [],
        private readonly array $metadata = [],
        private readonly float $duration = 0.0,
    ) {
    }

    /**
     * @return list<Variant>
     */
    public function variants(): array
    {
        return $this->variants;
    }

    /**
     * Every segment across every variant, or just one variant's when named.
     *
     * @return list<Segment>
     */
    public function segments(?string $variant = null): array
    {
        $segments = [];

        foreach ($this->variants as $candidate) {
            if ($variant !== null && $candidate->id !== $variant) {
                continue;
            }

            foreach ($candidate->segments as $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * Playlists written to disk. Empty when manifests were not kept.
     *
     * @return list<Manifest>
     */
    public function manifests(): array
    {
        return $this->manifests;
    }

    /**
     * Every artifact produced, ready to be handed to a storage layer.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->manifests as $manifest) {
            $files[] = $manifest->path;
        }

        foreach ($this->segments() as $segment) {
            $files[] = $segment->path;
        }

        return \array_values(\array_unique($files));
    }

    /**
     * Container level attributes, keyed by the manifest they came from.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function variant(string $id): ?Variant
    {
        foreach ($this->variants as $variant) {
            if ($variant->id === $id) {
                return $variant;
            }
        }

        return null;
    }

    public function size(): int
    {
        $size = 0;

        foreach ($this->segments() as $segment) {
            $size += $segment->size;
        }

        return $size;
    }
}
