<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * How to build sprite sheets for a scrubbing timeline.
 */
final class Tile
{
    private ?float $interval = null;

    private int $width = 160;

    private int $columns = 5;

    private int $rows = 5;

    private int $quality = 3;

    private string $name = 'sprite';

    private bool $vtt = true;

    /**
     * Seconds between thumbnails. Left unset, the interval scales with the
     * length of the source so a long film does not produce thousands of sheets.
     */
    public function interval(?float $seconds): self
    {
        $this->interval = $seconds;

        return $this;
    }

    public function width(int $pixels): self
    {
        $this->width = $pixels;

        return $this;
    }

    public function grid(int $columns, int $rows): self
    {
        $this->columns = $columns;
        $this->rows = $rows;

        return $this;
    }

    public function quality(int $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    public function name(string $base): self
    {
        $this->name = $base;

        return $this;
    }

    /**
     * Whether to write the WebVTT timeline alongside the sheets.
     */
    public function vtt(bool $write): self
    {
        $this->vtt = $write;

        return $this;
    }

    /**
     * Interval to use for a source of the given length.
     */
    public function every(float $duration): float
    {
        if ($this->interval !== null) {
            return $this->interval;
        }

        return match (true) {
            $duration < 120 => 2.0,
            $duration < 600 => 5.0,
            $duration < 1800 => 10.0,
            $duration < 3600 => 20.0,
            default => 30.0,
        };
    }

    public function size(): int
    {
        return $this->width;
    }

    public function columns(): int
    {
        return $this->columns;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function cells(): int
    {
        return $this->columns * $this->rows;
    }

    public function scale(): int
    {
        return $this->quality;
    }

    public function base(): string
    {
        return $this->name;
    }

    public function writes(): bool
    {
        return $this->vtt;
    }
}
