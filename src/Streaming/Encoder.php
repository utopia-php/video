<?php

namespace Utopia\Streaming;

use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Format\Format;

class Encoder
{

    /**
     * @var Adapter
     */
    private Adapter $adapter;


    /**
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @param string $path
     * @return FFmpeg
     */
    public function open(string $path): FFmpeg
    {
        return $this->adapter->open($path);
    }

    /**
     * @param string $path
     * @return bool
     */
    public function isValid(string $path): bool
    {
        return $this->adapter->isValid($path);
    }

    /**
     * @param Video $video
     * @return FFmpeg
     */
    public function setVideo(Video $video): FFmpeg
    {
        $this->adapter->setVideo($video);

        return $this;
    }

    /**
     * @param Format $format
     * @return FFmpeg
     */
    public function setFormat(Format $format): FFmpeg
    {
        $this->adapter->setFormat($format);

        return $this;
    }

}