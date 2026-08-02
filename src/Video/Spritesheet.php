<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * The result of tiling a source into sprite sheets for scrubbing previews.
 */
final class Spritesheet
{
    /**
     * @param  list<string>  $images
     * @param  list<Cue>  $cues
     */
    public function __construct(
        private readonly array $images = [],
        private readonly array $cues = [],
        private readonly ?string $vtt = null,
        private readonly int $width = 0,
        private readonly int $height = 0,
    ) {
    }

    /**
     * @return list<string>
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * @return list<Cue>
     */
    public function cues(): array
    {
        return $this->cues;
    }

    /**
     * Path of the written WebVTT timeline, or null when writing was disabled.
     */
    public function vtt(): ?string
    {
        return $this->vtt;
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        $files = $this->images;

        if ($this->vtt !== null) {
            $files[] = $this->vtt;
        }

        return $files;
    }

    /**
     * Width of a single thumbnail.
     */
    public function width(): int
    {
        return $this->width;
    }

    /**
     * Height of a single thumbnail.
     */
    public function height(): int
    {
        return $this->height;
    }

    /**
     * Renders the timeline as WebVTT, optionally rewriting each sheet URL.
     *
     * @param  callable(string):string|null  $url  Maps a sheet filename to the URL to publish.
     */
    public function render(?callable $url = null): string
    {
        $body = "WEBVTT\n";

        foreach ($this->cues as $cue) {
            $body .= "\n".$cue->render($url === null ? null : $url($cue->file))."\n";
        }

        return $body;
    }
}
