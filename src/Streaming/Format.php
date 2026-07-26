<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use FFMpeg\Format\Video\DefaultVideo;
use Utopia\Streaming\Exception\InvalidArgumentException;

abstract class Format extends DefaultVideo
{
    /**
     * Bitrate belongs on Representation, not Format.
     *
     * @param  int  $kiloBitrate
     * @return never
     */
    public function setKiloBitrate($kiloBitrate)
    {
        throw new InvalidArgumentException('You cannot set this option, use Representation instead');
    }

    /**
     * Audio bitrate belongs on Representation, not Format.
     *
     * @param  int  $kiloBitrate
     * @return never
     */
    public function setAudioKiloBitrate($kiloBitrate)
    {
        throw new InvalidArgumentException('You cannot set this option, use Representation instead');
    }
}
