<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * A single progress report from a running backend.
 *
 * Percentages are resolved against the duration discovered when the input was
 * opened, so listeners never have to supply it themselves.
 */
final class Progress
{
    public function __construct(
        public readonly float $percent = 0.0,
        public readonly float $time = 0.0,
        public readonly int $frame = 0,
        public readonly float $fps = 0.0,
        public readonly float $speed = 0.0,
    ) {
    }
}
