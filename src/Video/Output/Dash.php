<?php

declare(strict_types=1);

namespace Utopia\Video\Output;

use Utopia\Video\Name;
use Utopia\Video\Output;

/**
 * MPEG-DASH: one manifest describing every adaptation set.
 *
 * Addressing matters here. With templates on, the manifest describes segment
 * names by formula, which keeps it small. With both templates and timeline off
 * every segment is listed explicitly, which is what you need when the URLs a
 * player should request are not the filenames on disk.
 */
class Dash extends Output
{
    protected bool $template = true;

    protected bool $timeline = true;

    protected string $manifest = 'manifest.mpd';

    protected ?string $initName = null;

    protected ?string $mediaName = null;

    protected ?string $sets = null;

    public function type(): string
    {
        return Output::DASH;
    }

    /**
     * Describe segments by formula rather than listing them.
     */
    public function template(bool $enabled): static
    {
        $this->template = $enabled;

        return $this;
    }

    /**
     * Include an explicit timeline of segment durations.
     */
    public function timeline(bool $enabled): static
    {
        $this->timeline = $enabled;

        return $this;
    }

    public function manifest(string $filename): static
    {
        $this->manifest = Name::file($filename, 'Manifest');

        return $this;
    }

    /**
     * Pattern for initialisation segments, in ffmpeg's template syntax.
     */
    public function init(string $pattern): static
    {
        $this->initName = $pattern;

        return $this;
    }

    /**
     * Pattern for media segments, in ffmpeg's template syntax.
     */
    public function media(string $pattern): static
    {
        $this->mediaName = $pattern;

        return $this;
    }

    /**
     * Override how streams are grouped into adaptation sets.
     */
    public function sets(string $definition): static
    {
        $this->sets = $definition;

        return $this;
    }

    public function templated(): bool
    {
        return $this->template;
    }

    public function timelined(): bool
    {
        return $this->timeline;
    }

    /**
     * True when the manifest will list every segment individually.
     */
    public function listed(): bool
    {
        return ! $this->template && ! $this->timeline;
    }

    public function manifestFile(): string
    {
        return $this->manifest;
    }

    public function initPattern(): string
    {
        return $this->initName ?? $this->name.'_init_$RepresentationID$.$ext$';
    }

    public function mediaPattern(): string
    {
        return $this->mediaName ?? $this->name.'_chunk_$RepresentationID$_$Number%05d$.$ext$';
    }

    /**
     * How streams are grouped into adaptation sets.
     *
     * A set holds representations that are alternatives of one another, and a
     * player picks one from each set. Video rungs are alternatives, so they share
     * a set. Audio tracks in different languages are not — they are separate
     * choices — so each gets a set of its own, which is also the only place DASH
     * can record a language.
     *
     * @param  int  $video  Video rungs, occupying the first output streams.
     * @param  int  $audio  Audio tracks, following them.
     */
    public function adaptations(int $video = 1, int $audio = 1): string
    {
        if ($this->sets !== null) {
            return $this->sets;
        }

        $sets = [];
        $id = 0;

        if ($video > 0) {
            $sets[] = 'id='.$id++.',streams=v';
        }

        if ($audio === 1) {
            $sets[] = 'id='.$id++.',streams=a';
        } else {
            for ($track = 0; $track < $audio; $track++) {
                $sets[] = 'id='.$id++.',streams='.($video + $track);
            }
        }

        return \implode(' ', $sets);
    }
}
