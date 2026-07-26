<?php

declare(strict_types=1);

namespace Utopia\Streaming;

abstract class Output
{
    private string $strict = '-2';

    /** @var array<string, mixed> */
    private array $additionalParams = [];

    private bool $hasVideo = false;

    /**
     * @var list<array{codec: string, language: string}>
     */
    private array $audioTracks = [];

    public function setStrict(string $strict): self
    {
        $this->strict = $strict;

        return $this;
    }

    public function getStrict(): string
    {
        return $this->strict;
    }

    /**
     * @param  array<string, mixed>  $additionalParams
     */
    public function setAdditionalParams(array $additionalParams): self
    {
        $this->additionalParams = $additionalParams;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalParams(): array
    {
        return $this->additionalParams;
    }

    public function setHasVideo(bool $hasVideo): self
    {
        $this->hasVideo = $hasVideo;

        return $this;
    }

    public function hasVideo(): bool
    {
        return $this->hasVideo;
    }

    /**
     * @param  list<array{codec: string, language: string}>  $audioTracks
     */
    public function setAudioTracks(array $audioTracks): self
    {
        $this->audioTracks = $audioTracks;

        return $this;
    }

    /**
     * @return list<array{codec: string, language: string}>
     */
    public function getAudioTracks(): array
    {
        return $this->audioTracks;
    }
}
