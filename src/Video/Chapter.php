<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * A named span of a timeline, as carried by containers such as MP4 and Matroska.
 */
final class Chapter
{
    public function __construct(
        public readonly float $start,
        public readonly float $end,
        public readonly ?string $title = null,
    ) {
    }
}
