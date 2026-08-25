<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * One stream inside a container, whether or not it is the primary video or audio.
 */
final class Track
{
    public const VIDEO = 'video';

    public const AUDIO = 'audio';

    public const SUBTITLE = 'subtitle';

    public const DATA = 'data';

    /**
     * @param  array<string, string>  $tags
     */
    public function __construct(
        public readonly int $index,
        public readonly string $type,
        public readonly ?string $codec = null,
        public readonly ?string $language = null,
        public readonly ?string $title = null,
        public readonly bool $default = false,
        public readonly bool $forced = false,
        public readonly array $tags = [],
    ) {
    }
}
