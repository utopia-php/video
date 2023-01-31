<?php

namespace Utopia\Streaming;

use Utopia\Streaming\Format\Format;

class Stream
{
    private Encoder $encoder;

    /**
     * @param  Encoder  $encoder
     * @return void
     */
    public function __construct(Encoder $encoder)
    {
        $this->encoder = $encoder;
    }

    /**
     * @param  string  $path
     * @return self
     */
    public function open(string $path): self
    {
        $this->encoder->open($path);

        return $this;
    }

    /**
     * @param  Format  $format
     * @return self
     */
    public function setFormat(Format $format): self
    {
        $this->encoder->setFormat($format);

        return $this;
    }

    /**
     * @param  Representation  $representation
     * @return self
     */
    public function addRepresentation(Representation $representation): self
    {
        $this->encoder->addRepresentation($representation);

        return $this;
    }

    /**
     * @param  Output  $output
     * @return self
     */
    public function setOutput(Output $output): self
    {
        $this->encoder->setOutput($output);

        return $this;
    }


    public function run(): void
    {
        $this->encoder->run();
    }
}
