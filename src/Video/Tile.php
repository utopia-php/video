<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Exception\Input;

/**
 * How to build sprite sheets for a scrubbing timeline.
 *
 * Every knob is checked as it is set, the way a Representation checks its own.
 * These values become divisors and filter arguments, so a zero or a negative
 * reaches ffmpeg as a broken filter or never reaches it at all, and the failure
 * lands a long way from the call that caused it.
 *
 * Immutable: every setter returns a modified copy and leaves the receiver
 * untouched, so one instance can be shared across jobs and coroutines.
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
        if ($seconds !== null && $seconds <= 0) {
            throw new Input('Sprite interval must be greater than zero, got '.$seconds);
        }

        $copy = clone $this;
        $copy->interval = $seconds;

        return $copy;
    }

    public function width(int $pixels): self
    {
        if ($pixels < 2) {
            throw new Input('Sprite width must be at least 2 pixels, got '.$pixels);
        }

        $copy = clone $this;
        $copy->width = $pixels;

        return $copy;
    }

    public function grid(int $columns, int $rows): self
    {
        if ($columns < 1 || $rows < 1) {
            throw new Input('Sprite grid must be at least 1x1, got '.$columns.'x'.$rows);
        }

        $copy = clone $this;
        $copy->columns = $columns;
        $copy->rows = $rows;

        return $copy;
    }

    /**
     * Encoder quality scale, where lower is better. ffmpeg's -qscale:v runs 1 to 31.
     */
    public function quality(int $quality): self
    {
        if ($quality < 1 || $quality > 31) {
            throw new Input('Sprite quality must be between 1 and 31, got '.$quality);
        }

        $copy = clone $this;
        $copy->quality = $quality;

        return $copy;
    }

    public function name(string $base): self
    {
        $name = Name::label($base, 'Sprite sheet name');

        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    /**
     * Whether to write the WebVTT timeline alongside the sheets.
     */
    public function vtt(bool $write): self
    {
        $copy = clone $this;
        $copy->vtt = $write;

        return $copy;
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
