<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Adapter;
use Utopia\Video\Arguments;
use Utopia\Video\Cue;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\X264;
use Utopia\Video\Info;
use Utopia\Video\Manifest;
use Utopia\Video\Name;
use Utopia\Video\Output;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Package;
use Utopia\Video\Parser\M3u8;
use Utopia\Video\Parser\Mpd;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Spritesheet;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

/**
 * Everything the ffmpeg binary can do, behind one class.
 *
 * Encoding, packaging and pulling stills are one backend, not three: they drive
 * the same tool with the same configuration, and packaging in particular wants
 * to encode at the same time. Doing both in one invocation is the point — one
 * decode of the source feeds every rung of the ladder, and the muxer writes a
 * single master playlist describing all of them. Running one command per rung
 * would decode the source repeatedly and leave each run overwriting the last
 * one's master.
 *
 * Reading media details is a separate class, because it is a separate binary.
 */
class FFmpeg extends Adapter implements Encoder, Packager
{
    use Job;
    use Reads;

    protected const NAME = 'ffmpeg';

    protected const BINARY = 'ffmpeg';

    /** What was learned about the source when it was opened. */
    protected ?Info $details = null;

    public function open(string $path, ?Representation $as = null): static
    {
        // Asked before registering, which is what starts the job.
        $first = ! $this->started;

        $this->register($path, $as);

        if ($first) {
            // One probe per job, on the first input only.
            $this->details = $this->prober()->read($path);
        }

        return $this;
    }

    /**
     * What was learned about the source when it was opened.
     */
    public function info(): ?Info
    {
        return $this->details;
    }

    public function encode(string $path): string
    {
        [$source, $info] = $this->ready();

        if (\count($this->inputs) > 1) {
            throw new Input(self::NAME.': encode() writes a single file; use pack() for a ladder');
        }

        if (\count($this->reps) > 1) {
            throw new Input(self::NAME.': encode() writes a single file; use pack() for a ladder');
        }

        $format = $this->encoding ?? new X264();
        $rep = $this->reps[0] ?? null;

        $this->directory(\dirname($path));

        $video = $info->hasVideo && ($rep === null || $rep->scaled());

        $args = $this->prefix();
        $args[] = '-i';
        $args[] = $source;

        if ($video) {
            $args[] = '-map';
            $args[] = '0:v:0';
        }

        // One rendition of the picture, but every audio track: a file with four
        // dubs should come out with all four, not just the first.
        if ($info->hasAudio) {
            $args[] = '-map';
            $args[] = '0:a';
        }

        foreach ($format->build($video, $info->hasAudio) as $arg) {
            $args[] = $arg;
        }

        if ($rep !== null && $video && $rep->scaled()) {
            $args[] = '-vf';
            $args[] = 'scale='.$rep->width.':'.$rep->height.':force_original_aspect_ratio=decrease,'
                .'pad='.$rep->width.':'.$rep->height.':(ow-iw)/2:(oh-ih)/2,setsar=1:1';
            $args[] = '-b:v';
            $args[] = $rep->video.'k';
            $args[] = '-maxrate';
            $args[] = $rep->maxrate.'k';
            $args[] = '-bufsize';
            $args[] = $rep->bufsize.'k';
        }

        if ($rep !== null && $info->hasAudio) {
            $args[] = '-b:a';
            $args[] = $rep->audio.'k';
        }

        $args[] = $path;

        try {
            $this->execute($args, $info->duration);

            $path = $this->wrote($path);
            $this->reportSuccess(self::NAME.': encoded '.$path);

            return $path;
        } finally {
            // Both terminals end the job, so the next open() starts a new one
            // instead of adding a second input to this one.
            $this->done();
        }
    }

    public function pack(string $dir): Package
    {
        [, $info] = $this->ready();

        if ($this->target === null) {
            throw new Unsupported(self::NAME.': no output format has been set');
        }

        if ($this->reps === []) {
            throw new Unsupported(self::NAME.': at least one representation is required');
        }

        $output = $this->target;
        $dir = $this->directory($dir);

        $builder = Arguments::for(
            $info,
            $this->encoding ?? new X264(),
            $this->reps,
            $output,
            $dir,
            \count($this->inputs),
        );

        $args = $this->prefix();

        foreach ($this->paths() as $input) {
            $args[] = '-i';
            $args[] = $input;
        }

        foreach ($builder->build() as $arg) {
            $args[] = $arg;
        }

        $args[] = $builder->target();

        try {
            $this->execute($args, $info->duration);

            $package = $this->result($dir, $output, $info, $info->duration);
            $this->reportSuccess(self::NAME.': packed '.$dir);

            return $package;
        } finally {
            // The job is over, so the next open() starts a new one.
            $this->done();
        }
    }

    public function grab(string $path, string $output, ?Thumb $options = null): string
    {
        $path = $this->source($path);
        $options ??= new Thumb();
        $info = $this->prober()->read($path);

        // A sound file with artwork has no video to speak of, but it does have a
        // picture, so look for one before giving up.
        $cover = $info->cover;

        if (! $info->hasVideo && $cover === null) {
            throw new Input(self::NAME.': source "'.$path.'" carries no image to grab');
        }

        $this->directory(\dirname($output));

        $args = $this->prefix(progress: false);
        $at = $options->at();

        if ($at !== null && $cover === null) {
            // Seeking before the input is far quicker than decoding up to the mark.
            $args[] = '-ss';
            $args[] = self::number($at);
        }

        $args[] = '-i';
        $args[] = $path;
        $args[] = '-map';

        // Embedded artwork is a single frame; nothing needs selecting.
        $args[] = $cover !== null ? '0:'.$cover : '0:v:0';

        $filters = [];

        if ($at === null && $cover === null) {
            // Let ffmpeg pick the most representative frame it can find.
            $filters[] = 'thumbnail';
        }

        if ($options->size() > 0) {
            $filters[] = 'scale='.$options->size().':-2';
        }

        if ($filters !== []) {
            $args[] = '-vf';
            $args[] = \implode(',', $filters);
        }

        $args[] = '-frames:v';
        $args[] = '1';
        $args[] = '-qscale:v';
        $args[] = (string) $options->scale();
        $args[] = $output;

        $this->process($args);

        $output = $this->wrote($output);
        $this->reportSuccess(self::NAME.': grabbed '.$output);

        return $output;
    }

    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet
    {
        $path = $this->source($path);
        $options ??= new Tile();
        $info = $this->prober()->read($path);

        if (! $info->hasVideo || ! $info->width || ! $info->height) {
            throw new Input(self::NAME.': source "'.$path.'" carries no video to tile');
        }

        if ($info->duration <= 0) {
            throw new Input(self::NAME.': source "'.$path.'" has no measurable duration');
        }

        $dir = $this->directory($dir);

        $interval = $options->every($info->duration);
        $width = $options->size();
        $height = (int) \round($width / ($info->width / $info->height));
        $height += $height % 2;

        $args = $this->prefix(progress: false);
        $args[] = '-i';
        $args[] = $path;
        $args[] = '-fps_mode';
        $args[] = 'vfr';
        $args[] = '-vf';
        $args[] = 'select=isnan(prev_selected_t)+gte(t-prev_selected_t\\,'.self::number($interval).')'
            .',scale='.$width.':'.$height
            .',tile='.$options->columns().'x'.$options->rows();
        $args[] = '-qscale:v';
        $args[] = (string) $options->scale();
        $args[] = $dir.'/'.$options->base().'%d.jpg';

        // The sheets this run writes are numbered from one, and they are read
        // back by counting upward until one is missing. A shorter run over the
        // same directory would therefore inherit the tail of a longer one, so
        // the numbering starts from an empty slate.
        $this->sweep($dir, $options->base());

        $this->process($args);

        $sheet = $this->sheet($dir, $options, $info->duration, $interval, $width, $height);
        $this->reportSuccess(self::NAME.': tiled '.$dir);

        return $sheet;
    }

    /**
     * Arguments that come before the input on every invocation.
     *
     * @param  bool  $progress  Whether to ask for the machine readable progress
     *                          stream. Single-frame work finishes before anything
     *                          could listen, so it does not bother.
     * @return list<string>
     */
    protected function prefix(bool $progress = true): array
    {
        $args = [$this->binary, '-y', '-hide_banner', '-loglevel', $this->level];

        if ($progress) {
            $args[] = '-progress';
            $args[] = 'pipe:1';
            $args[] = '-nostats';
        }

        if ($this->threads > 0) {
            $args[] = '-threads';
            $args[] = (string) $this->threads;
        }

        return $args;
    }

    /**
     * Runs ffmpeg, translating its progress stream into events.
     *
     * @param  list<string>  $args
     */
    protected function execute(array $args, float $duration): void
    {
        $frame = 0;
        $fps = 0.0;
        $speed = 0.0;

        $this->process(
            $args,
            function (string $line) use (&$frame, &$fps, &$speed, $duration): void {
                $split = \strpos($line, '=');

                if ($split === false) {
                    return;
                }

                $key = \trim(\substr($line, 0, $split));
                $value = \trim(\substr($line, $split + 1));

                switch ($key) {
                    case 'frame':
                        $frame = (int) $value;

                        break;
                    case 'fps':
                        $fps = (float) $value;

                        break;
                    case 'speed':
                        $speed = (float) \rtrim($value, 'x');

                        break;
                    case 'out_time_us':
                    case 'out_time_ms':
                        // Both keys are reported in microseconds.
                        $time = ((float) $value) / 1000000;
                        $this->emit(Observable::PROGRESS, new Progress(
                            percent: $duration > 0 ? \min(100.0, \round(($time / $duration) * 100, 2)) : 0.0,
                            time: \max(0.0, $time),
                            frame: $frame,
                            fps: $fps,
                            speed: $speed,
                        ));

                        break;
                    case 'progress':
                        if ($value === 'end') {
                            $this->emit(Observable::PROGRESS, new Progress(
                                percent: 100.0,
                                time: $duration,
                                frame: $frame,
                                fps: $fps,
                                speed: $speed,
                            ));
                        }

                        break;
                }
            },
            function (string $line): void {
                if (\trim($line) !== '') {
                    $this->emit(Observable::LOG, $line);
                }
            },
        );
    }

    /**
     * Confirms the chain is complete enough to run, and hands back the source
     * together with what was learned about it.
     *
     * @return array{0: string, 1: Info}
     */
    protected function ready(): array
    {
        $source = $this->opened();

        if ($this->details === null) {
            throw new Input(self::NAME.': no source has been opened');
        }

        return [$source, $this->details];
    }

    /**
     * Removes the numbered sheets a previous run under this name left behind.
     *
     * Deliberately narrow: only the exact `{base}{digits}.jpg` shape that this
     * adapter writes and sheet() reads back, so a directory shared with anything
     * else — including a sheet name that merely starts the same way — keeps it.
     */
    private function sweep(string $dir, string $base): void
    {
        $matches = \glob($dir.'/'.$base.'[0-9]*.jpg');

        if ($matches === false) {
            return;
        }

        $shape = '/^'.\preg_quote($base, '/').'\d+\.jpg$/';

        foreach ($matches as $match) {
            if (\preg_match($shape, \basename($match)) === 1) {
                @\unlink($match);
            }
        }
    }

    /**
     * Walks the sheets ffmpeg wrote and works out which slice covers when.
     */
    private function sheet(
        string $dir,
        Tile $options,
        float $duration,
        float $interval,
        int $width,
        int $height,
    ): Spritesheet {
        $images = [];
        $cues = [];
        $thumbs = (int) \ceil($duration / $interval);
        $cells = $options->cells();
        $index = 0;

        for ($sheet = 1; ; $sheet++) {
            $file = $options->base().$sheet.'.jpg';
            $path = $dir.'/'.$file;

            if (! \is_file($path)) {
                break;
            }

            $images[] = $path;

            for ($cell = 0; $cell < $cells && $index < $thumbs; $cell++, $index++) {
                $start = $index * $interval;
                $end = \min($start + $interval, $duration);

                if ($end <= $start) {
                    break;
                }

                $cues[] = new Cue(
                    start: $start,
                    end: $end,
                    file: $file,
                    x: ($cell % $options->columns()) * $width,
                    y: \intdiv($cell, $options->columns()) * $height,
                    width: $width,
                    height: $height,
                );
            }
        }

        if ($images === []) {
            throw new Runtime(self::NAME.': produced no sprite sheets');
        }

        $vtt = null;

        if ($options->writes()) {
            $vtt = $dir.'/'.$options->base().'.vtt';
            $sheet = new Spritesheet($images, $cues, null, $width, $height);

            if (\file_put_contents($vtt, $sheet->render()) === false) {
                throw new Runtime(self::NAME.': unable to write "'.$vtt.'"');
            }
        }

        return new Spritesheet($images, $cues, $vtt, $width, $height);
    }

    /**
     * Reads back what was written and describes it.
     */
    private function result(string $dir, Output $output, Info $info, float $duration): Package
    {
        $manifests = [];

        if ($output instanceof Cmaf) {
            $master = $dir.'/'.$output->masterFile();
            $mpd = $dir.'/'.$output->manifestFile();

            self::independent($master);
            self::spoken($master, $info);

            $read = Mpd::read($mpd, $dir);
            $manifests[] = new Manifest(Manifest::DASH, $mpd, true);

            if (\is_file($master)) {
                $manifests[] = new Manifest(Manifest::HLS, $master, true);

                foreach (self::playlists($dir, $master) as $playlist) {
                    $manifests[] = new Manifest(Manifest::HLS, $playlist);
                }
            }
        } elseif ($output instanceof Dash) {
            $mpd = $dir.'/'.$output->manifestFile();
            $read = Mpd::read($mpd, $dir);
            $manifests[] = new Manifest(Manifest::DASH, $mpd, true);
        } elseif ($output instanceof Hls) {
            $master = $dir.'/'.$output->masterFile();

            self::independent($master);
            self::spoken($master, $info);

            $read = M3u8::read($master, $dir);
            $manifests[] = new Manifest(Manifest::HLS, $master, true);

            foreach ($read['playlists'] as $playlist) {
                $manifests[] = new Manifest(Manifest::HLS, $playlist);
            }
        } else {
            throw new Unsupported(self::NAME.': unsupported output "'.$output::class.'"');
        }

        $metadata = $read['metadata'];
        $metadata['duration'] ??= $duration;

        if (! $output->keeps()) {
            foreach ($manifests as $manifest) {
                @\unlink($manifest->path);
            }

            $manifests = [];
        }

        return new Package(
            variants: $read['variants'],
            manifests: $manifests,
            metadata: $metadata,
            duration: $duration,
        );
    }

    /**
     * Media playlists written next to a master, excluding the master itself.
     *
     * @return list<string>
     */
    private static function playlists(string $dir, string $master): array
    {
        $found = [];

        foreach (\glob($dir.'/*.m3u8') ?: [] as $path) {
            if ($path !== $master) {
                $found[] = $path;
            }
        }

        \sort($found);

        return $found;
    }

    /**
     * Names the language of each audio rendition in an HLS master.
     *
     * The hls muxer writes LANGUAGE itself, but the dash muxer's HLS output does
     * not — so a CMAF package would offer several dubs with nothing to tell them
     * apart. Renditions are emitted in stream order, which is the order the
     * source reported its audio tracks in, so they line up.
     */
    private static function spoken(string $master, Info $info): void
    {
        if ($info->audioTracks === [] || ! \is_file($master)) {
            return;
        }

        $body = \file_get_contents($master);

        if ($body === false) {
            return;
        }

        $languages = \array_map(
            static fn (array $track): string => Name::language($track['language']),
            $info->audioTracks,
        );

        $position = 0;
        $lines = \explode("\n", $body);

        foreach ($lines as $index => $line) {
            if (! \str_starts_with($line, '#EXT-X-MEDIA:TYPE=AUDIO')) {
                continue;
            }

            $language = $languages[$position++] ?? null;

            if ($language === null || $language === '' || \str_contains($line, 'LANGUAGE=')) {
                continue;
            }

            $lines[$index] = $line.',LANGUAGE="'.$language.'"';
        }

        self::rewrite($master, \implode("\n", $lines));
    }

    /**
     * Records in the master that segments can be decoded independently.
     *
     * Forcing keyframes makes this true, but the muxers do not say so reliably:
     * the dash muxer never writes the tag at all, and the hls muxer writes it
     * into the video playlist while leaving it off the audio one. Declaring it
     * once in the master covers every rendition, and players switch quality
     * faster when they can rely on it.
     */
    private static function independent(string $master): void
    {
        if (! \is_file($master)) {
            return;
        }

        $body = \file_get_contents($master);

        if ($body === false || \str_contains($body, '#EXT-X-INDEPENDENT-SEGMENTS')) {
            return;
        }

        $lines = \explode("\n", $body);
        $at = 0;

        foreach ($lines as $index => $line) {
            if (\str_starts_with($line, '#EXT-X-VERSION')) {
                $at = $index + 1;

                break;
            }

            if (\str_starts_with($line, '#EXTM3U')) {
                $at = $index + 1;
            }
        }

        \array_splice($lines, $at, 0, '#EXT-X-INDEPENDENT-SEGMENTS');
        self::rewrite($master, \implode("\n", $lines));
    }

    /**
     * Writes a manifest back over itself.
     *
     * Failing quietly here would leave a package whose master says something
     * different from what was asked for, which is worse than not packaging.
     *
     * @throws Runtime
     */
    private static function rewrite(string $master, string $body): void
    {
        if (\file_put_contents($master, $body) === false) {
            throw new Runtime(self::NAME.': unable to write "'.$master.'"');
        }
    }
}
