<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use FFMpeg\Media\AbstractMediaType;

/**
 * Assembles the full ffmpeg argv for a packaging job.
 */
final class CommandBuilder
{
    private AbstractMediaType $media;

    private Format $format;

    /** @var array<string, mixed> */
    private array $inputOptions;

    /**
     * @param  array<string, mixed>  $inputOptions
     */
    public function __construct(
        AbstractMediaType $media,
        Format $format,
        array $inputOptions = []
    ) {
        $this->media = $media;
        $this->format = $format;
        $this->inputOptions = $inputOptions;
    }

    /**
     * @param  list<string>  $argumentGroups
     * @return list<string>
     */
    public function build(array $argumentGroups, string $outputPath): array
    {
        $commands = [
            ...Utils::arrayToFFmpegOpt($this->inputOptions),
            ...($this->format->getInitialParameters() ?: []),
            '-y',
            '-i',
            $this->media->getPathfile(),
            ...$argumentGroups,
        ];

        $driver = $this->media->getFFMpegDriver();
        if ($driver->getConfiguration()->has('ffmpeg.threads')) {
            $commands[] = '-threads';
            $commands[] = (string) $driver->getConfiguration()->get('ffmpeg.threads');
        }

        $commands[] = $outputPath;

        return $commands;
    }
}
