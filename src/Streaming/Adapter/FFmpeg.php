<?php

declare(strict_types=1);

namespace Utopia\Streaming\Adapter;

use FFMpeg\Exception\ExceptionInterface as FFmpegExceptionInterface;
use FFMpeg\FFMpeg as BaseFFmpeg;
use FFMpeg\FFProbe as BaseFFProbe;
use FFMpeg\Media\AbstractMediaType;
use Utopia\Streaming\Adapter;
use Utopia\Streaming\Arguments;
use Utopia\Streaming\Arguments\Dash as DashArguments;
use Utopia\Streaming\Arguments\Hls as HlsArguments;
use Utopia\Streaming\CommandBuilder;
use Utopia\Streaming\Exception\InvalidArgumentException;
use Utopia\Streaming\Exception\RuntimeException;
use Utopia\Streaming\File;
use Utopia\Streaming\Format;
use Utopia\Streaming\Output;
use Utopia\Streaming\Output\Dash as DashOutput;
use Utopia\Streaming\Output\Hls as HlsOutput;
use Utopia\Streaming\Probe;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Representations;

class FFmpeg implements Adapter
{
    protected BaseFFProbe $ffprobe;

    protected BaseFFmpeg $ffmpeg;

    protected ?Probe $probe = null;

    protected ?AbstractMediaType $media = null;

    protected ?Format $format = null;

    protected ?Output $output = null;

    protected Representations $reps;

    /** @var array<string, mixed> */
    protected array $inputOptions = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->ffprobe = BaseFFProbe::create($config);
        $this->ffmpeg = BaseFFmpeg::create($config, null, $this->ffprobe);
        $this->reps = new Representations();
    }

    public function open(string $path): self
    {
        $streams = $this->ffprobe->streams($path);

        $hasVideo = count($streams->videos()) > 0;
        $hasAudio = count($streams->audios()) > 0;

        if (! $hasVideo && ! $hasAudio) {
            throw new InvalidArgumentException(
                'Unable to detect file format, only audio and video are supported'
            );
        }

        $format = null;
        try {
            $format = $this->ffprobe->format($path);
        } catch (\Throwable) {
            // Duration may still be available on individual streams.
        }

        $this->probe = new Probe($streams, $format);

        try {
            $this->media = $this->ffmpeg->open($path);
        } catch (FFmpegExceptionInterface $e) {
            throw new RuntimeException(
                'An error occurred while opening the file: '.$e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $this;
    }

    public function isValid(string $path): bool
    {
        try {
            return (float) $this->ffprobe->format($path)->get('duration') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setFormat(Format $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function addRepresentation(Representation $representation): self
    {
        $this->reps->add($representation);

        return $this;
    }

    /**
     * @param  list<Representation>  $reps
     */
    public function addRepresentations(array $reps): self
    {
        foreach ($reps as $representation) {
            $this->addRepresentation($representation);
        }

        return $this;
    }

    public function setOutput(Output $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $inputOptions
     */
    public function setInputOptions(array $inputOptions): self
    {
        $this->inputOptions = $inputOptions;

        return $this;
    }

    public function save(?string $path = null): self
    {
        $this->assertReady();

        /** @var Probe $probe */
        $probe = $this->probe;
        /** @var Format $format */
        $format = $this->format;
        /** @var Output $output */
        $output = $this->output;
        /** @var AbstractMediaType $media */
        $media = $this->media;

        $output
            ->setHasVideo($probe->hasVideo())
            ->setAudioTracks($probe->getAudioTracks());

        $resolvedPath = $this->resolvePath($path);
        $arguments = $this->createArguments($resolvedPath, $format, $output);
        $outputPath = $arguments->getOutputPath();

        File::makeDir(dirname($outputPath));

        $commands = (new CommandBuilder($media, $format, $this->inputOptions))
            ->build($arguments->build(), $outputPath);

        $pass = (int) $format->getPasses();
        $listeners = $format->createProgressListener(
            $media,
            $media->getFFProbe(),
            1,
            $pass
        );

        try {
            $media->getFFMpegDriver()->command($commands, false, $listeners);
        } catch (FFmpegExceptionInterface $e) {
            throw new RuntimeException(
                'An error occurred while saving files: '.$e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $this;
    }

    public function run(): void
    {
        $this->save(null);
    }

    public function getProbe(): ?Probe
    {
        return $this->probe;
    }

    public function getRepresentations(): Representations
    {
        return $this->reps;
    }

    private function assertReady(): void
    {
        if ($this->media === null || $this->probe === null) {
            throw new InvalidArgumentException('Call open() before save()');
        }

        if ($this->format === null) {
            throw new InvalidArgumentException('Call setFormat() before save()');
        }

        if ($this->output === null) {
            throw new InvalidArgumentException('Call setOutput() before save()');
        }

        if ($this->reps->count() === 0) {
            throw new InvalidArgumentException('Add at least one representation before save()');
        }
    }

    private function resolvePath(?string $path): string
    {
        if ($path !== null) {
            if (strlen($path) > PHP_MAXPATHLEN) {
                throw new InvalidArgumentException('The path is too long');
            }

            File::makeDir(dirname($path));

            return $path;
        }

        /** @var AbstractMediaType $media */
        $media = $this->media;

        return $media->getPathfile();
    }

    private function createArguments(string $path, Format $format, Output $output): Arguments
    {
        if ($output instanceof HlsOutput) {
            return new HlsArguments($path, $format, $output, $this->reps);
        }

        if ($output instanceof DashOutput) {
            return new DashArguments($path, $format, $output, $this->reps);
        }

        throw new InvalidArgumentException(
            'Unsupported output type: '.get_class($output)
        );
    }
}
