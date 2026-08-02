<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * One media segment, or the initialisation segment that precedes them.
 */
final class Segment
{
    public function __construct(
        public readonly string $variant,
        public readonly string $file,
        public readonly string $path,
        public readonly float $duration = 0.0,
        public readonly bool $init = false,
        public readonly int $number = 0,
        public readonly int $size = 0,
    ) {
    }
}
