<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * Everything a probe could learn about a source, in backend neutral form.
 *
 * The technical fields describe the container and its primary video and audio
 * stream. Descriptive metadata lives alongside them, and `raw` always holds the
 * untouched backend payload for anything this class does not model.
 */
final class Info
{
    /**
     * @param  list<array{codec: string, language: string}>  $audioTracks
     * @param  array<string, string>  $tags
     * @param  list<Track>  $tracks
     * @param  list<Chapter>  $chapters
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly float $duration = 0.0,
        public readonly string $format = '',
        public readonly bool $hasVideo = false,
        public readonly bool $hasAudio = false,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $aspect = null,
        public readonly ?float $fps = null,
        public readonly ?string $fpsMode = null,
        public readonly ?string $videoCodec = null,
        public readonly ?string $videoFormat = null,
        public readonly ?string $videoProfile = null,
        public readonly ?int $videoBitrate = null,
        public readonly ?string $audioCodec = null,
        public readonly ?string $audioFormat = null,
        public readonly ?int $audioBitrate = null,
        public readonly ?int $sampleRate = null,
        public readonly array $audioTracks = [],
        public readonly array $tags = [],
        public readonly array $tracks = [],
        public readonly array $chapters = [],
        public readonly ?int $rotation = null,
        /**
         * Stream index of an embedded cover image, when the source carries one.
         *
         * A sound file with artwork reports no video, but there is still a
         * picture to be had; this is where it is.
         */
        public readonly ?int $cover = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Duration in milliseconds, which is how most catalogues store it.
     */
    public function milliseconds(): int
    {
        return (int) \round($this->duration * 1000);
    }

    /**
     * Display aspect ratio derived from the frame size when the container does
     * not carry one of its own.
     */
    public function ratio(): ?string
    {
        if ($this->aspect !== null) {
            return $this->aspect;
        }

        if (! $this->width || ! $this->height) {
            return null;
        }

        $divisor = self::gcd($this->width, $this->height);

        return ($this->width / $divisor).':'.($this->height / $divisor);
    }

    /**
     * @return list<Track>
     */
    public function tracks(string $type): array
    {
        return \array_values(\array_filter(
            $this->tracks,
            static fn (Track $track): bool => $track->type === $type,
        ));
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }
}
