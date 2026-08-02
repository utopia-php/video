<?php

declare(strict_types=1);

namespace Utopia\Video\Output;

use Utopia\Video\Output;

/**
 * HTTP Live Streaming: a master playlist pointing at one media playlist per rung.
 */
final class Hls extends Output
{
    public const MPEGTS = 'mpegts';

    public const FMP4 = 'fmp4';

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
     */
    public function segments(string $type): static
    {
        $this->segments = $type;

        return $this;
    }

    /**
     * Name of the initialisation segment, used by fragmented MP4 output only.
     */
    public function init(string $filename): static
    {
        $this->init = $filename;

        return $this;
    }

    public function master(string $filename): static
    {
        $this->master = $filename;

        return $this;
    }

    /**
     * Prefix written in front of every segment reference in the playlists.
     */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @param  list<string>  $flags
     */
    public function flags(array $flags): static
    {
        $this->flags = $flags;

        return $this;
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
