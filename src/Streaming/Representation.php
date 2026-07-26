<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use Utopia\Streaming\Exception\InvalidArgumentException;

class Representation
{
    protected int $width = 0;

    protected int $height = 0;

    protected int $videoKiloBitrate = 0;

    protected int $audioKiloBitrate = 0;

    public function setResize(int $width, int $height): self
    {
        if ($width < 50 || $height < 50) {
            throw new InvalidArgumentException('Invalid video resize value');
        }

        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Resolution as an ffmpeg size string, e.g. "1280x720".
     */
    public function getResolution(): string
    {
        return $this->width.'x'.$this->height;
    }

    public function setVideoKiloBitrate(int $videoKiloBitrate): self
    {
        if ($videoKiloBitrate < 1) {
            throw new InvalidArgumentException('Invalid video kilo bit rate value');
        }

        $this->videoKiloBitrate = $videoKiloBitrate;

        return $this;
    }

    /**
     * Alias matching the fork's Representation::setKiloBitrate().
     */
    public function setKiloBitrate(int $kiloBitrate): self
    {
        return $this->setVideoKiloBitrate($kiloBitrate);
    }

    public function getVideoKiloBitrate(): int
    {
        return $this->videoKiloBitrate;
    }

    /**
     * Alias matching the fork's Representation::getKiloBitrate().
     */
    public function getKiloBitrate(): int
    {
        return $this->videoKiloBitrate;
    }

    public function setAudioKiloBitrate(int $audioKiloBitrate): self
    {
        if ($audioKiloBitrate < 1) {
            throw new InvalidArgumentException('Invalid audio kilo bit rate value');
        }

        $this->audioKiloBitrate = $audioKiloBitrate;

        return $this;
    }

    public function getAudioKiloBitrate(): int
    {
        return $this->audioKiloBitrate;
    }
}
