<?php

declare(strict_types=1);

namespace Utopia\Streaming\Output;

use Utopia\Streaming\Output;

class Dash extends Output
{
    private string $adaption = '';

    private int $segmentDuration = 10;

    private bool $generateHlsPlaylist = false;

    private int $useTimeline = 0;

    private int $useTemplate = 0;

    private bool $initSegmentName = false;

    private bool $mediaSegmentName = false;

    public function setAdaption(string $adaption): self
    {
        $this->adaption = $adaption;

        return $this;
    }

    public function getAdaption(): string
    {
        return $this->adaption;
    }

    public function setSegmentDuration(int $segmentDuration): self
    {
        $this->segmentDuration = $segmentDuration;

        return $this;
    }

    public function getSegmentDuration(): int
    {
        return $this->segmentDuration;
    }

    public function generateHlsPlaylist(bool $generateHlsPlaylist = true): self
    {
        $this->generateHlsPlaylist = $generateHlsPlaylist;

        return $this;
    }

    public function isGenerateHlsPlaylist(): bool
    {
        return $this->generateHlsPlaylist;
    }

    public function setUseTimeline(int $useTimeline): self
    {
        $this->useTimeline = $useTimeline;

        return $this;
    }

    public function getUseTimeline(): int
    {
        return $this->useTimeline;
    }

    public function setUseTemplate(int $useTemplate): self
    {
        $this->useTemplate = $useTemplate;

        return $this;
    }

    public function getUseTemplate(): int
    {
        return $this->useTemplate;
    }

    public function setInitSegmentName(bool $initSegmentName): self
    {
        $this->initSegmentName = $initSegmentName;

        return $this;
    }

    public function getInitSegmentName(): bool
    {
        return $this->initSegmentName;
    }

    public function setMediaSegmentName(bool $mediaSegmentName): self
    {
        $this->mediaSegmentName = $mediaSegmentName;

        return $this;
    }

    public function getMediaSegmentName(): bool
    {
        return $this->mediaSegmentName;
    }
}
