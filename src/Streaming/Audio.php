<?php

namespace Utopia\Streaming;


use FFMpeg\FFProbe\DataMapping\StreamCollection;

class Audio
{

    protected StreamCollection $audioStreams;


    /**
     * @param StreamCollection $streams
     * @return Audio
     */
    public function setAudioStreams(StreamCollection $streams): Audio
    {
        $this->audioStreams = $streams;

        return $this;
    }

}