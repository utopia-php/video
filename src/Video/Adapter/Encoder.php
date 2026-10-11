<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Format;
use Utopia\Video\Representation;
use Utopia\Video\Spritesheet;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

/**
 * Turns a source into a single encoded file, and pulls stills out of one.
 *
 * Stills belong here rather than in an interface of their own because they are
 * the same work: decode the source, write a picture. Anything that can encode
 * can grab a frame, and PHP 8.1 cannot express "an encoder that also does
 * stills" as an optional constructor parameter, so keeping them together is
 * what lets the facade accept any encoding backend at all.
 */
interface Encoder extends Named, Observable
{
    /**
     * Point the encoder at a source and read what it needs to know about it.
     *
     * This starts a new job: the format, representations and listeners left
     * over from a previous one are dropped, so a single adapter can be reused
     * without the old settings bleeding into the new job.
     */
    public function open(string $path): static;

    public function format(Format $format): static;

    public function add(Representation ...$representations): static;

    /**
     * Encode to a single file and return the path written.
     */
    public function encode(string $path): string;

    /**
     * Write a single still and return its path.
     */
    public function grab(string $path, string $output, ?Thumb $options = null): string;

    /**
     * Write sprite sheets covering the whole timeline.
     */
    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet;

    /**
     * Write one text subtitle stream as WebVTT and return its path.
     *
     * @param  int  $stream  The container stream index, as Track::$index reports it.
     * @param  int|null  $timeout  Seconds before the run is halted; null keeps the adapter's own.
     */
    public function subtitle(string $path, int $stream, string $output, ?int $timeout = null): string;
}
