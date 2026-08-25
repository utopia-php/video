<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * A playlist or manifest file written next to the segments it describes.
 */
final class Manifest
{
    public const HLS = 'hls';

    public const DASH = 'dash';

    public function __construct(
        public readonly string $type,
        public readonly string $path,
        public readonly bool $main = false,
    ) {
    }

    public function file(): string
    {
        return \basename($this->path);
    }
}
