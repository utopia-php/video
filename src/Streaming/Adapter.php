<?php

namespace Utopia\Streaming;

use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Encoder;
use Utopia\Streaming\Format\Format;

interface Adapter
{

    /**
     * @param  string  $path
     * @return self
     */
    public function open(string $path): self;

    /**
     * @param  string  $path
     * @return bool
     */
    public function isValid(string $path): bool;

    /**
     * @param Video $video
     * @return self
     */
    public function setVideo(Video $video): self;

    /**
     * @param Format $format
     * @return self
     */
    public function setFormat(Format $format): self;

    /**
     * @param Representation $representation
     * @return self
     */
    public function addRepresentation(Representation $representation): self;

    /**
     * @param Output $output
     * @return self
     */
    public function setOutput(Output $output): self;


    public function run(): void;
}