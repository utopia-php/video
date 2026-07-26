<?php

declare(strict_types=1);

namespace Utopia\Streaming\Output;

use Utopia\Streaming\Output;

class Hls extends Output
{
    private int $segmentDuration = 10;

    private bool $allowCache = true;

    private string $segmentSubDirectory = '';

    private string $baseUrl = '';

    private int $playlistSize = 0;

    private string $segmentType = 'mpegts';

    private string $initFilename = 'init.mp4';

    private string $masterPlaylistName = 'master.m3u8';

    /** @var list<string> */
    private array $flags = [];

    public function setSegmentDuration(int $segmentDuration): self
    {
        $this->segmentDuration = $segmentDuration;

        return $this;
    }

    public function getSegmentDuration(): int
    {
        return $this->segmentDuration;
    }

    public function setAllowCache(bool $allowCache): self
    {
        $this->allowCache = $allowCache;

        return $this;
    }

    public function allowsCache(): bool
    {
        return $this->allowCache;
    }

    public function setSegmentSubDirectory(string $segmentSubDirectory): self
    {
        $this->segmentSubDirectory = $segmentSubDirectory;

        return $this;
    }

    public function getSegmentSubDirectory(): string
    {
        return $this->segmentSubDirectory;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setPlaylistSize(int $playlistSize): self
    {
        $this->playlistSize = $playlistSize;

        return $this;
    }

    public function getPlaylistSize(): int
    {
        return $this->playlistSize;
    }

    public function setSegmentType(string $segmentType): self
    {
        $this->segmentType = $segmentType;

        return $this;
    }

    public function getSegmentType(): string
    {
        return $this->segmentType;
    }

    public function fragmentedMp4(): self
    {
        return $this->setSegmentType('fmp4');
    }

    public function setInitFilename(string $initFilename): self
    {
        $this->initFilename = $initFilename;

        return $this;
    }

    public function getInitFilename(): string
    {
        return $this->initFilename;
    }

    public function setMasterPlaylistName(string $masterPlaylistName): self
    {
        $this->masterPlaylistName = $masterPlaylistName;

        return $this;
    }

    public function getMasterPlaylistName(): string
    {
        return $this->masterPlaylistName;
    }

    /**
     * @param  list<string>  $flags
     */
    public function setFlags(array $flags): self
    {
        $this->flags = array_values(array_merge($this->flags, $flags));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }
}
