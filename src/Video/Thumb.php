<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Exception\Input;

/**
 * How to pick and size a single still frame.
 *
 * Checked as it is set, the way a Representation checks its own values, so a
 * size ffmpeg cannot scale to is refused here rather than deep inside a filter.
 *
 * Immutable: every setter returns a modified copy and leaves the receiver
 * untouched, so one instance can be shared across jobs and coroutines.
 */
final class Thumb
{
    private ?float $at = null;

    private int $width = 320;

    private int $quality = 2;

    /**
     * Seek to a specific second, or leave unset to let the encoder choose the
     * most representative frame it can find.
     */
    public function time(?float $seconds): self
    {
        if ($seconds !== null && $seconds < 0) {
            throw new Input('Thumbnail time cannot be negative, got '.$seconds);
        }

        $copy = clone $this;
        $copy->at = $seconds;

        return $copy;
    }

    /**
     * Output width in pixels; height follows the source aspect. Zero keeps the
     * original size.
     */
    public function width(int $pixels): self
    {
        if ($pixels !== 0 && $pixels < 2) {
            throw new Input('Thumbnail width must be 0 or at least 2 pixels, got '.$pixels);
        }

        $copy = clone $this;
        $copy->width = $pixels;

        return $copy;
    }

    /**
     * Encoder quality scale, where lower is better. ffmpeg's -qscale:v runs 1 to 31.
     */
    public function quality(int $quality): self
    {
        if ($quality < 1 || $quality > 31) {
            throw new Input('Thumbnail quality must be between 1 and 31, got '.$quality);
        }

        $copy = clone $this;
        $copy->quality = $quality;

        return $copy;
    }

    public function at(): ?float
    {
        return $this->at;
    }

    public function size(): int
    {
        return $this->width;
    }

    public function scale(): int
    {
        return $this->quality;
    }
}
