<?php

namespace Utopia\Streaming;

use Utopia\Streaming\Format\Format;

class Encoder
{

    private Adapter $adapter;

    /**
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @param  string  $path
     * @return Adapter
     */
    public function open(string $path): Adapter
    {
        return $this->adapter->open($path);
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function isValid(string $path): bool
    {
        return $this->adapter->isValid($path);
    }


    /**
     * @param  Format  $format
     * @return Encoder
     */
    public function setFormat(Format $format): Encoder
    {
        $this->adapter->setFormat($format);

        return $this;
    }

    /**
     * @param  Representation  $representation
     * @return Encoder
     */
    public function addRepresentation(Representation $representation): Encoder
    {
        $this->adapter->addRepresentation($representation);

        return $this;
    }

    /**
     * @param $representations Representation[]
     * @return Encoder
     */
    public function addRepresentations(array $representations): Encoder
    {
        foreach ($representations as $representation) {
            $this->adapter->addRepresentation($representation);
        }

        return $this;
    }

    /**
     * @param  Output  $output
     * @return Encoder
     */
    public function setOutput(Output $output): Encoder
    {
        $this->adapter->setOutput($output);

        return $this;
    }

    public function run(): void
    {
        $this->adapter->run();
    }
}
