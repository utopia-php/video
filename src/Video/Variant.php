<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * One selectable rendition of a package: an HLS variant or a DASH adaptation set.
 */
final class Variant
{
    /**
     * @param  list<Segment>  $segments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type = Track::VIDEO,
        public readonly ?string $mimeType = null,
        public readonly ?string $codecs = null,
        public readonly int $bandwidth = 0,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $sar = null,
        public readonly ?int $sampleRate = null,
        public readonly ?string $language = null,
        public readonly int $timescale = 0,
        public readonly int $startNumber = 0,
        public readonly float $target = 0.0,
        public readonly array $segments = [],
        public readonly ?string $playlist = null,
    ) {
    }

    public function resolution(): ?string
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return $this->width.'x'.$this->height;
    }
}
