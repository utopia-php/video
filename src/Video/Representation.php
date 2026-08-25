<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Exception\Input;

/**
 * One rung of an adaptive ladder.
 *
 * Bitrates are capped by default: without an explicit ceiling a rendition is
 * allowed to reach its own target rate and no further, which keeps a busy
 * scene from spiking past what the advertised bandwidth promises.
 *
 * The name is the one field a caller can put anything in, and it is used to
 * build filenames and to identify the rendition to the muxer, so it is checked
 * rather than trusted.
 */
final class Representation
{
    public readonly string $name;

    public readonly int $maxrate;

    public readonly int $bufsize;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $video,
        public readonly int $audio = 128,
        ?string $name = null,
        ?int $maxrate = null,
        ?int $bufsize = null,
    ) {
        if ($width < 0 || $height < 0) {
            throw new Input('Representation dimensions cannot be negative');
        }

        if ($width % 2 !== 0 || $height % 2 !== 0) {
            throw new Input('Representation dimensions must be even, got '.$width.'x'.$height);
        }

        if ($video < 1) {
            throw new Input('Representation video bitrate must be at least 1 kbps');
        }

        if ($audio < 1) {
            throw new Input('Representation audio bitrate must be at least 1 kbps');
        }

        $this->name = $name === null
            ? ($height > 0 ? $height.'p' : 'audio')
            : Name::label($name, 'Representation name');
        $this->maxrate = $maxrate ?? $video;
        $this->bufsize = $bufsize ?? ($this->maxrate * 2);
    }

    public function resolution(): string
    {
        return $this->width.'x'.$this->height;
    }

    /**
     * An audio only rung carries no frame size.
     */
    public function scaled(): bool
    {
        return $this->width > 0 && $this->height > 0;
    }
}
