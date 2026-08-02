<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Adapter;
use Utopia\Video\Cue;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Info;
use Utopia\Video\Manifest;
use Utopia\Video\Package;
use Utopia\Video\Progress;
use Utopia\Video\Segment;
use Utopia\Video\Spritesheet;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;
use Utopia\Video\Track;
use Utopia\Video\Variant;

/**
 * A backend that pretends, so tests do not need real tools.
 *
 * It writes small placeholder files and returns results shaped like the real
 * thing, which is enough to exercise a pipeline end to end — including progress
 * events and job restarts — on a machine with no ffmpeg installed. Useful in
 * this library's own unit suite, and to anyone testing code built on top.
 *
 * Because it encodes as well as packages, a Packager given only a Mock always
 * takes the single-pass route.
 */
class Mock extends Adapter implements Encoder, Packager, Probe
{
    use Job;

    protected const NAME = 'mock';

    /** Seconds the pretend source lasts. */
    private float $duration = 8.0;

    private int $width = 640;

    private int $height = 480;

    private bool $audio = true;

    /**
     * Describe the source this Mock should pretend to have been given.
     */
    public function pretend(
        float $duration = 8.0,
        int $width = 640,
        int $height = 480,
        bool $audio = true,
    ): static {
        $this->duration = $duration;
        $this->width = $width;
        $this->height = $height;
        $this->audio = $audio;

        return $this;
    }

    public function read(string $path): Info
    {
        $this->source($path);

        return new Info(
            duration: $this->duration,
            format: 'mock',
            hasVideo: $this->width > 0 && $this->height > 0,
            hasAudio: $this->audio,
            width: $this->width > 0 ? $this->width : null,
            height: $this->height > 0 ? $this->height : null,
            fps: 25.0,
            videoCodec: $this->width > 0 ? 'h264' : null,
            audioCodec: $this->audio ? 'aac' : null,
            audioTracks: $this->audio ? [['codec' => 'aac', 'language' => 'und']] : [],
            tracks: $this->tracks(),
        );
    }

    public function valid(string $path): bool
    {
        return \is_file($path) && $this->duration > 0;
    }

    public function info(): ?Info
    {
        $first = $this->inputs[0]['path'] ?? null;

        return $first === null ? null : $this->read($first);
    }

    public function encode(string $path): string
    {
        $source = $this->opened();

        if (\count($this->reps) > 1) {
            throw new Input(self::NAME.': encode() writes a single file; use pack() for a ladder');
        }

        $this->directory(\dirname($path));
        $this->progress();

        \file_put_contents($path, 'mock encode of '.\basename($source));

        try {
            $path = $this->wrote($path);
            $this->reportSuccess(self::NAME.': encoded '.$path);

            return $path;
        } finally {
            // Both terminals end the job, as in the real adapters.
            $this->inputs = [];
        }
    }

    public function pack(string $dir): Package
    {
        if ($this->inputs === []) {
            throw new Input(self::NAME.': no source has been opened');
        }

        if ($this->target === null) {
            throw new Unsupported(self::NAME.': no output format has been set');
        }

        if ($this->reps === []) {
            throw new Unsupported(self::NAME.': at least one representation is required');
        }

        $output = $this->target;
        $dir = $this->directory($dir);
        $this->progress();

        $variants = [];

        foreach (\array_values($this->reps) as $position => $rep) {
            $segments = [];

            foreach ([0, 1] as $number) {
                $file = $output->base().'_'.$rep->name.'_'.$number.'.ts';
                \file_put_contents($dir.'/'.$file, 'mock segment');

                $segments[] = new Segment(
                    variant: (string) $position,
                    file: $file,
                    path: $dir.'/'.$file,
                    duration: $this->duration / 2,
                    number: $number,
                    size: (int) \filesize($dir.'/'.$file),
                );
            }

            $variants[] = new Variant(
                id: (string) $position,
                type: Track::VIDEO,
                bandwidth: $rep->video * 1000,
                width: $rep->width,
                height: $rep->height,
                target: $output->duration(),
                segments: $segments,
            );
        }

        $manifests = [];

        if ($output->keeps()) {
            $master = $dir.'/'.$output->base().'.m3u8';
            \file_put_contents($master, "#EXTM3U\n");
            $manifests[] = new Manifest(Manifest::HLS, $master, true);
        }

        $this->inputs = [];
        $this->reportSuccess(self::NAME.': packed '.$dir);

        return new Package(
            variants: $variants,
            manifests: $manifests,
            metadata: ['duration' => $this->duration],
            duration: $this->duration,
        );
    }

    public function grab(string $path, string $output, ?Thumb $options = null): string
    {
        $this->source($path);
        $this->directory(\dirname($output));

        \file_put_contents($output, 'mock still');

        $output = $this->wrote($output);
        $this->reportSuccess(self::NAME.': grabbed '.$output);

        return $output;
    }

    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet
    {
        $this->source($path);
        $options ??= new Tile();
        $dir = $this->directory($dir);

        $interval = $options->every($this->duration);
        $width = $options->size();
        $height = (int) \round($width / ($this->width / \max(1, $this->height)));

        $file = $options->base().'1.jpg';
        \file_put_contents($dir.'/'.$file, 'mock sheet');

        $cues = [];
        $thumbs = (int) \ceil($this->duration / $interval);

        for ($index = 0; $index < $thumbs && $index < $options->cells(); $index++) {
            $cues[] = new Cue(
                start: $index * $interval,
                end: \min(($index + 1) * $interval, $this->duration),
                file: $file,
                x: ($index % $options->columns()) * $width,
                y: \intdiv($index, $options->columns()) * $height,
                width: $width,
                height: $height,
            );
        }

        $vtt = null;

        if ($options->writes()) {
            $vtt = $dir.'/'.$options->base().'.vtt';
            \file_put_contents($vtt, (new Spritesheet([$dir.'/'.$file], $cues))->render());
        }

        $this->reportSuccess(self::NAME.': tiled '.$dir);

        return new Spritesheet([$dir.'/'.$file], $cues, $vtt, $width, $height);
    }

    protected function prober(): Probe
    {
        return $this->prober ?? $this;
    }

    /**
     * Emits the same shape of progress a real backend would.
     *
     * Progress is structured data rather than commentary, so it is reported at
     * every level; the log line is commentary, so a quiet backend withholds it.
     */
    private function progress(): void
    {
        foreach ([25.0, 50.0, 100.0] as $percent) {
            $this->emit(Observable::PROGRESS, new Progress(
                percent: $percent,
                time: $this->duration * ($percent / 100),
            ));
        }

        if ($this->level !== self::QUIET) {
            $this->emit(Observable::LOG, self::NAME.': done');
        }
    }

    /**
     * @return list<Track>
     */
    private function tracks(): array
    {
        $tracks = [];

        if ($this->width > 0 && $this->height > 0) {
            $tracks[] = new Track(index: 0, type: Track::VIDEO, codec: 'h264');
        }

        if ($this->audio) {
            $tracks[] = new Track(index: \count($tracks), type: Track::AUDIO, codec: 'aac');
        }

        return $tracks;
    }
}
