<?php

namespace Utopia\Streaming\Format;

use FFMpeg\Exception\InvalidArgumentException;

abstract class Format
{
    protected int $modulus = 2;

    protected string $audioCodec;

    protected string $videoCodec;

    protected array $additionalParameters;

    /**
     * @Return string
     */
    public function getAudioCodec(): string
    {
        return $this->audioCodec;
    }

    /**
     * Sets the audio codec, Should be in the available ones, otherwise an
     * exception is thrown.
     *
     * @param  string  $audioCodec
     *
     * @throws InvalidArgumentException
     */
    public function setAudioCodec(string $audioCodec): Format
    {
        if (! in_array($audioCodec, $this->getAvailableAudioCodecs())) {
            throw new InvalidArgumentException(sprintf(
                'Wrong audiocodec value for %s, available formats are %s', $audioCodec, implode(', ', $this->getAvailableAudioCodecs())
            ));
        }

        $this->audioCodec = $audioCodec;

        return $this;
    }

    /**
     * @Return string
     */
    public function getVideoCodec(): string
    {
        return $this->videoCodec;
    }

    /**
     * Sets the video codec, Should be in the available ones, otherwise an
     * exception is thrown.
     *
     * @param  string  $videoCodec
     *
     * @throws InvalidArgumentException
     */
    public function setVideoCodec(string $videoCodec)
    {
        if (! in_array($videoCodec, $this->getAvailableVideoCodecs())) {
            throw new InvalidArgumentException(sprintf(
                'Wrong videocodec value for %s, available formats are %s', $videoCodec, implode(', ', $this->getAvailableVideoCodecs())
            ));
        }

        $this->videoCodec = $videoCodec;

        return $this;
    }

    /**
     * @param  array  $additionalParameters
     * @return Format
     */
    public function setAdditionalParameters(array $additionalParameters): Format
    {
        $this->additionalParameters = $additionalParameters;

        return $this;
    }

    /**
     * @return int
     */
    public function getModulus(): int
    {
        return $this->modulus;
    }
}
