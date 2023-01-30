<?php

namespace Utopia\Streaming;

use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Format\Format;

interface Adapter
{

    /**
     * @param  string  $path
     * @return FFmpeg
     */
    public function open(string $path): FFmpeg;

    /**
     * @param  string  $path
     * @return bool
     */
    public function isValid(string $path): bool;

    /**
     * @param Video $video
     * @return FFmpeg
     */
    public function setVideo(Video $video): FFmpeg;

    /**
     * @param Format $format
     * @return FFmpeg
     */
    public function setFormat(Format $format): FFmpeg;

}