<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * A segment the backend has finished writing, reported while the job runs.
 *
 * The size is taken before the event fires, so a listener is free to upload
 * the file and delete it: the package returned at the end still lists it,
 * with this size.
 */
final class Written
{
    public function __construct(
        public readonly string $file,
        public readonly string $path,
        public readonly int $size,
    ) {
    }
}
