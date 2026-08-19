<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Exception\Unsupported;

/**
 * Builds the ffmpeg arguments for one packaging job.
 *
 * Everything downstream of the input file is decided here, in one command per
 * job. Doing it in a single command matters: it is what lets one decode feed
 * every rung of the ladder, and what keeps a single master playlist describing
 * all of them.
 *
 * @internal
 */
abstract class Arguments
{
    use Decimal;

    /**
     * @param  list<Representation>  $reps
     * @param  int  $inputs  How many files were opened. More than one means each
     *                       rung reads from its own already encoded input.
     */
    public function __construct(
        protected readonly Info $info,
        protected readonly Format $format,
        protected readonly array $reps,
        protected readonly Output $output,
        protected readonly string $dir,
        protected readonly int $inputs = 1,
    ) {
        if ($reps === []) {
            throw new Unsupported('At least one representation is required');
        }

        if (! \in_array($output->type(), $format->supports(), true)) {
            throw new Unsupported(
                \strtoupper($output->type()).' cannot carry '.$format->codec()
                .'; supported: '.\implode(', ', $format->supports()),
            );
        }

        $interval = $format->interval();

        // A cut can only land on a keyframe, so keyframes further apart than a
        // segment cannot produce segments of the length that was asked for. The
        // muxer would carry on regardless and write longer ones.
        if ($interval !== null && $interval > $output->duration()) {
            throw new Unsupported(
                'A keyframe every '.self::number($interval).'s cannot cut '
                .self::number($output->duration()).'s segments; segments start on keyframes, so the '
                .'keyframe interval has to be the segment length or a fraction of it',
            );
        }
    }

    /**
     * The builder that matches the requested output.
     *
     * Keeping the choice here means callers deal with one name rather than
     * importing three builders alongside the three outputs they mirror.
     *
     * @param  list<Representation>  $reps
     */
    public static function for(
        Info $info,
        Format $format,
        array $reps,
        Output $output,
        string $dir,
        int $inputs = 1,
    ): self {
        return match (true) {
            $output instanceof Output\Cmaf => new Arguments\Cmaf($info, $format, $reps, $output, $dir, $inputs),
            $output instanceof Output\Dash => new Arguments\Dash($info, $format, $reps, $output, $dir, $inputs),
            $output instanceof Output\Hls => new Arguments\Hls($info, $format, $reps, $output, $dir, $inputs),
            default => throw new Unsupported('Unsupported output "'.$output::class.'"'),
        };
    }

    /**
     * Arguments that follow the input file, excluding the output path.
     *
     * @return list<string>
     */
    abstract public function build(): array;

    /**
     * The path ffmpeg is asked to write.
     */
    abstract public function target(): string;

    /**
     * Whether this job produces video at all.
     */
    protected function visual(): bool
    {
        if (! $this->info->hasVideo) {
            return false;
        }

        foreach ($this->reps as $rep) {
            if ($rep->scaled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Audio streams to carry through, one entry per output audio stream.
     *
     * Every track comes through, tagged or not: a language is what tells one
     * rendition from another in a manifest, but a track without one is still a
     * track, and dropping it would lose a dub the source actually carried.
     * Untagged tracks are reported with an empty language, which the builders
     * read as "nothing to say about this one".
     *
     * @return list<array{index: int, language: string}>
     */
    protected function sound(): array
    {
        if (! $this->info->hasAudio) {
            return [];
        }

        $tracks = [];

        foreach ($this->info->audioTracks as $index => $track) {
            $language = $track['language'];

            $tracks[] = [
                'index' => $index,
                'language' => $language === 'und' ? '' : $language,
            ];
        }

        // A probe that found audio without describing it still has one track.
        return $tracks === [] ? [['index' => 0, 'language' => '']] : $tracks;
    }

    /**
     * Stream selection: each video rung reads the same source stream, and every
     * audio track is carried once.
     *
     * Audio always comes from the first input. With one input that is the source
     * itself; with several it is the first encoded rung, which carries every
     * track the source had, so the track indices hold either way.
     *
     * @return list<string>
     */
    protected function maps(): array
    {
        $args = [];
        $split = $this->inputs > 1;

        foreach ($this->rungs() as $position => $rep) {
            $args[] = '-map';
            $args[] = ($split ? $position : 0).':v:0';
        }

        foreach ($this->sound() as $track) {
            $args[] = '-map';
            $args[] = '0:a:'.$track['index'];
        }

        return $args;
    }

    /**
     * Codec arguments plus the per-rung sizing and rate control.
     *
     * @return list<string>
     */
    protected function streams(): array
    {
        $visual = $this->visual();
        $tracks = $this->sound();

        // The segment length doubles as the keyframe cadence unless the caller
        // named one, so a ladder packaged with the defaults is still cut on
        // keyframes rather than wherever the encoder happened to put one.
        $args = $this->format->build($visual, $tracks !== [], $this->output->duration());

        if ($visual) {
            $position = 0;

            foreach ($this->reps as $rep) {
                if (! $rep->scaled()) {
                    continue;
                }

                foreach ($this->scale($rep, $position) as $arg) {
                    $args[] = $arg;
                }

                $position++;
            }
        }

        foreach (\array_keys($tracks) as $position) {
            $rep = $this->reps[\min($position, \count($this->reps) - 1)];

            $args[] = '-b:a:'.$position;
            $args[] = $rep->audio.'k';
        }

        return $args;
    }

    /**
     * Sizing and rate control for a single rung.
     *
     * The frame is fitted inside the requested box and padded back out to it,
     * so the resolution advertised in the manifest is the resolution delivered
     * no matter what shape the source was.
     *
     * @return list<string>
     */
    protected function scale(Representation $rep, int $position): array
    {
        return [
            '-filter:v:'.$position,
            'scale='.$rep->width.':'.$rep->height.':force_original_aspect_ratio=decrease,'
                .'pad='.$rep->width.':'.$rep->height.':(ow-iw)/2:(oh-ih)/2,setsar=1:1',
            '-b:v:'.$position,
            $rep->video.'k',
            '-maxrate:v:'.$position,
            $rep->maxrate.'k',
            '-bufsize:v:'.$position,
            $rep->bufsize.'k',
        ];
    }

    /**
     * Representations that actually produce a video rung.
     *
     * @return list<Representation>
     */
    protected function rungs(): array
    {
        if (! $this->visual()) {
            return [];
        }

        return \array_values(\array_filter(
            $this->reps,
            static fn (Representation $rep): bool => $rep->scaled(),
        ));
    }

    protected function path(string $file): string
    {
        return \rtrim($this->dir, '/').'/'.$file;
    }
}
