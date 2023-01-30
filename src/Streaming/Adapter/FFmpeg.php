<?php

namespace Utopia\Streaming\Adapter;

use FFMpeg\Exception\InvalidArgumentException;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\FFMpeg as BFFmpeg;
use FFMpeg\FFProbe as BFFProbe;
use Utopia\Streaming\Adapter;
use Utopia\Streaming\Video;
use Utopia\Streaming\Format\Format;

class FFmpeg implements Adapter
{

    protected BFFProbe $ffprobe;
    protected BFFmpeg $ffmpeg;
    protected Video $video;
    public Format $format;

    /**
     * @param array $config
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->ffprobe = BFFProbe::create();
        $this->ffmpeg = BFFMpeg::create($config,null, $this->ffprobe);
    }

    /**
     * @param string $path
     * @return FFmpeg
     */
    public function open(string $path): FFmpeg
    {
        if (null === $streams = $this->ffprobe->streams($path)) {
            throw new RuntimeException(sprintf('Unable to probe "%s".', $path));
        }

        if (0 < count($streams->videos())) {
            $this->setVideo(new Video($streams));

            return $this;
        }

        throw new InvalidArgumentException('Unable to detect file format');
    }

    /**
     * @param string $path
     * @return bool
     */
    public function isValid(string $path): bool
    {
        try {
            return $this->ffprobe->format($path)->get('duration') > 0;
        } catch(\Exception $e) {
            return false;
        }
    }

    /**
     * @param Video $video
     * @return FFmpeg
     */
    public function setVideo(Video $video): FFmpeg
    {
        $this->video = $video;

        return $this;
    }

    /**
     * @param Format $format
     * @return FFmpeg
     */
    public function setFormat(Format $format): FFmpeg
    {
     $this->format = $format;

     return $this;
    }

}


