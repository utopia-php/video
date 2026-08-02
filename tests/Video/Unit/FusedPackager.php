<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Video\Adapter\Encoder;
use Utopia\Video\Format;
use Utopia\Video\Spritesheet;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

/**
 * A packager that can encode too — the shape ffmpeg has, which is what puts a
 * job on the single-pass route.
 */
class FusedPackager extends FakePackager implements Encoder
{
    public ?Format $format = null;

    public function valid(string $path): bool
    {
        return true;
    }

    public function format(Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function encode(string $path): string
    {
        return $path;
    }

    public function grab(string $path, string $output, ?Thumb $options = null): string
    {
        return $output;
    }

    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet
    {
        return new Spritesheet([$dir.'/sprite1.jpg'], []);
    }
}
