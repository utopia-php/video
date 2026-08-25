<?php

declare(strict_types=1);

namespace Utopia\Video\Output;

use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Name;
use Utopia\Video\Output;

/**
 * HTTP Live Streaming: a master playlist pointing at one media playlist per rung.
 */
final class Hls extends Output
{
    public const MPEGTS = 'mpegts';

    public const FMP4 = 'fmp4';

    /**
     * Every container the hls muxer can segment into.
     *
     * @var list<string>
     */
    public const CONTAINERS = [self::MPEGTS, self::FMP4];

    private string $segments = self::MPEGTS;

    private string $init = 'init.mp4';

    private string $master = 'master.m3u8';

    private string $url = '';

    /** @var list<string> */
    private array $flags = ['independent_segments'];

    public function type(): string
    {
        return Output::HLS;
    }

    /**
     * Transport stream segments, or fragmented MP4 for CMAF compatible output.
     *
     * Rejected here rather than by the muxer: a container it does not know is a
     * typo, and a typo is worth catching before a ladder has been encoded.
     *
     * @throws Unsupported
     */
    public function segments(string $type): static
    {
        if (! \in_array($type, self::CONTAINERS, true)) {
            throw new Unsupported(
                'No HLS segment container named "'.$type.'"; expected one of '
                .\implode(', ', self::CONTAINERS),
            );
        }

        $copy = clone $this;
        $copy->segments = $type;

        return $copy;
    }

    /**
     * Name of the initialisation segment, used by fragmented MP4 output only.
     */
    public function init(string $filename): static
    {
        $init = Name::file($filename, 'Initialisation segment');

        $copy = clone $this;
        $copy->init = $init;

        return $copy;
    }

    public function master(string $filename): static
    {
        $master = Name::file($filename, 'Master playlist');

        $copy = clone $this;
        $copy->master = $master;

        return $copy;
    }

    /**
     * Prefix written in front of every segment reference in the playlists.
     */
    public function url(string $url): static
    {
        $url = Name::prefix($url, 'Segment URL prefix');

        $copy = clone $this;
        $copy->url = $url;

        return $copy;
    }

    /**
     * @param  list<string>  $flags
     */
    public function flags(array $flags): static
    {
        foreach ($flags as $flag) {
            Name::word($flag, 'HLS flag');
        }

        $copy = clone $this;
        $copy->flags = $flags;

        return $copy;
    }

    public function fragmented(): bool
    {
        return $this->segments === self::FMP4;
    }

    public function container(): string
    {
        return $this->segments;
    }

    public function extension(): string
    {
        return $this->fragmented() ? 'm4s' : 'ts';
    }

    public function initFile(): string
    {
        return $this->init;
    }

    public function masterFile(): string
    {
        return $this->master;
    }

    public function prefix(): string
    {
        return $this->url;
    }

    /**
     * @return list<string>
     */
    public function hlsFlags(): array
    {
        return $this->flags;
    }
}
