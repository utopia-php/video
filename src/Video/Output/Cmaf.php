<?php

declare(strict_types=1);

namespace Utopia\Video\Output;

use Utopia\Video\Name;
use Utopia\Video\Output;

/**
 * One set of fragmented MP4 segments, described by both a DASH manifest and an
 * HLS playlist tree.
 *
 * Storing a single copy of the media and letting either protocol address it is
 * the whole point: DASH players and Apple players read different manifests but
 * download exactly the same bytes.
 */
final class Cmaf extends Dash
{
    private string $master = 'master.m3u8';

    public function __construct()
    {
        $this->manifest = 'manifest.mpd';

        // Segment addressing has to be explicit for the two manifests to stay
        // in step with each other, and listing is what CMAF consumers expect.
        $this->template = false;
        $this->timeline = false;
    }

    public function type(): string
    {
        return Output::CMAF;
    }

    public function master(string $filename): static
    {
        $master = Name::file($filename, 'Master playlist');

        $copy = clone $this;
        $copy->master = $master;

        return $copy;
    }

    public function masterFile(): string
    {
        return $this->master;
    }
}
