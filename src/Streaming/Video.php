<?php

namespace Utopia\Streaming;

use FFMpeg\FFProbe\DataMapping\StreamCollection;

class Video extends Audio
{

    protected StreamCollection $videoStreams;


    /**
     * @param StreamCollection $streams
     */
    public function __construct(StreamCollection $streams)
    {
        $this->setVideoStreams($streams->videos());
        $this->setAudioStreams($streams->audios());
    }

    /**
     * @param $streams
     * @return Video
     */
    public function setVideoStreams($streams): Video
    {
        $this->videoStreams = $streams;

        return $this;
    }

}