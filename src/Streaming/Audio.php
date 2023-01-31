<?php

namespace Utopia\Streaming;

use FFMpeg\FFProbe\DataMapping\StreamCollection;

class Audio
{
    protected StreamCollection $audioStreams;

    protected array $audioTracks;

    /**
     * @param  StreamCollection  $streams
     * @return Audio
     */
    public function setAudioStreams(StreamCollection $streams): Audio
    {
        $this->audioStreams = $streams;

        foreach ($this->audioStreams->getIterator() ?? [] as $stream) {
            if (! empty($stream->get('tags')['language'])) {
                $this->audioTracks[] = $stream->get('tags')['language'];
            }
        }

        return $this;
    }
}
