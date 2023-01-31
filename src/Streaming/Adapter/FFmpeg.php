<?php

namespace Utopia\Streaming\Adapter;

use FFMpeg\Exception\InvalidArgumentException;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\FFMpeg as BFFmpeg;
use FFMpeg\FFProbe as BFFProbe;
use Utopia\Streaming\Adapter;
use Utopia\Streaming\Format\Format;
use Utopia\Streaming\Output;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Video;

class FFmpeg implements Adapter
{
    protected BFFProbe $ffprobe;

    protected BFFmpeg $ffmpeg;

    protected Video $video;

    protected array $representations;

    public Format $format;

    public Output $output;

    /**
     * @param  array  $config
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->ffprobe = BFFProbe::create();
        $this->ffmpeg = BFFMpeg::create($config, null, $this->ffprobe);
    }

    /**
     * @param  string  $path
     * @return self
     */
    public function open(string $path): self
    {
        if (null === $streams = $this->ffprobe->streams($path)) {
            throw new RuntimeException(sprintf('Unable to probe "%s".', $path));
        }

        if (0 < count($streams->videos())) {
            $this->setVideo(new Video($streams));

            return $this;
        }

        throw new InvalidArgumentException('Unable to detect file format');
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function isValid(string $path): bool
    {
        try {
            return $this->ffprobe->format($path)->get('duration') > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param  Video  $video
     * @return self
     */
    public function setVideo(Video $video): self
    {
        $this->video = $video;

        return $this;
    }

    /**
     * @param  Format  $format
     * @return self
     */
    public function setFormat(Format $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * @param  Representation  $representation
     * @return self
     */
    public function addRepresentation(Representation $representation): self
    {
        $this->representations[] = $representation;

        return $this;
    }

    /**
     * @param  Output  $output
     * @return self
     */
    public function setOutput(Output $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function run(): void
    {
        var_dump($this->output);
    }
}
