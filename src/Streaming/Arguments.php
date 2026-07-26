<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use Utopia\Streaming\Format as StreamingFormat;

/**
 * Builds a flat ffmpeg argv fragment for a packaging job.
 */
abstract class Arguments
{
    protected string $dirname;

    protected string $filename;

    protected StreamingFormat $format;

    protected Output $output;

    protected Representations $reps;

    public function __construct(
        string $path,
        StreamingFormat $format,
        Output $output,
        Representations $reps
    ) {
        $normalized = str_replace('\\', '/', $path);
        $this->dirname = pathinfo($normalized, PATHINFO_DIRNAME);
        $this->filename = pathinfo($normalized, PATHINFO_FILENAME);
        $this->format = $format;
        $this->output = $output;
        $this->reps = $reps;
    }

    /**
     * Flat argv fragment (without input options or final output path).
     *
     * @return list<string>
     */
    abstract public function build(): array;

    /**
     * Path that becomes the ffmpeg output argument.
     */
    abstract public function getOutputPath(): string;

    /**
     * @return list<string>
     */
    protected function formatOptions(): array
    {
        $codecs = [
            'c:a' => $this->format->getAudioCodec(),
        ];

        if ($this->output->hasVideo()) {
            $codecs = [
                'c:v' => $this->format->getVideoCodec(),
                'c:a' => $this->format->getAudioCodec(),
            ];
        }

        $basic = Utils::arrayToFFmpegOpt($codecs);
        $extra = Utils::arrayToFFmpegOpt($this->format->getAdditionalParameters() ?: []);

        return [...$basic, ...$extra];
    }

    protected function basePath(): string
    {
        return $this->dirname.'/'.$this->filename;
    }
}
