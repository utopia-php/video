<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * How to pick and size a single still frame.
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
        $this->at = $seconds;

        return $this;
    }

    /**
     * Output width in pixels; height follows the source aspect. Zero keeps the
     * original size.
     */
    public function width(int $pixels): self
    {
        $this->width = $pixels;

        return $this;
    }

    /**
     * Encoder quality scale, where lower is better.
     */
    public function quality(int $quality): self
    {
        $this->quality = $quality;

        return $this;
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
