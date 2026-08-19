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
        $this->master = Name::file($filename, 'Master playlist');

        return $this;
    }

    public function masterFile(): string
    {
        return $this->master;
    }

    public function initPattern(): string
    {
        return $this->initName ?? $this->name.'_init_$RepresentationID$.$ext$';
    }

    public function mediaPattern(): string
    {
        return $this->mediaName ?? $this->name.'_chunk_$RepresentationID$_$Number%05d$.$ext$';
    }
}
