<?php

namespace Utopia\Streaming;

use FFMpeg\Exception\InvalidArgumentException;

class Representation
{
    public function __construct()
    {
    }

    protected int $width = 0;

    protected int $height = 0;

    protected int $videoKiloBitrate = 0;

    protected int $audioKiloBitrate = 0;

    /**
     * @param  int  $width
     * @param  int  $height
     * @return Representation
     */
    public function setResize(int $width, int $height): Representation
    {
        if ($width < 50 || $height < 50) {
            throw new InvalidArgumentException('Invalid video resize value');
        }

        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * @return int
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * @return int
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return implode('x', [$this->getWidth(), $this->getHeight()]);
    }

    /**
     * @param  int  $videoKiloBitrate
     * @return Representation
     */
    public function setVideoKiloBitrate(int $videoKiloBitrate): Representation
    {
        if ($videoKiloBitrate < 1) {
            throw new InvalidArgumentException('Invalid video kilo bit rate value');
        }

        $this->videoKiloBitrate = $videoKiloBitrate;

        return $this;
    }

    /**
     * @return int
     */
    public function getVideoKiloBitrate(): int
    {
        return $this->videoKiloBitrate;
    }

    /**
     * @param  int  $audioKiloBitrate
     * @return Representation
     */
    public function setAudioKiloBitrate(int $audioKiloBitrate): Representation
    {
        if ($audioKiloBitrate < 1) {
            throw new InvalidArgumentException('Invalid audio kilo bit rate value');
        }

        $this->audioKiloBitrate = $audioKiloBitrate;

        return $this;
    }

    /**
     * @return int
     */
    public function getAudioKiloBitrate(): int
    {
        return $this->audioKiloBitrate;
    }
}
