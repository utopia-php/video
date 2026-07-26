<?php

declare(strict_types=1);

namespace Utopia\Streaming;

class Stream
{
    private Adapter $adapter;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    public function open(string $path): self
    {
        $this->adapter->open($path);

        return $this;
    }

    public function isValid(string $path): bool
    {
        return $this->adapter->isValid($path);
    }

    public function setFormat(Format $format): self
    {
        $this->adapter->setFormat($format);

        return $this;
    }

    public function addRepresentation(Representation $representation): self
    {
        $this->adapter->addRepresentation($representation);

        return $this;
    }

    /**
     * @param  list<Representation>  $reps
     */
    public function addRepresentations(array $reps): self
    {
        $this->adapter->addRepresentations($reps);

        return $this;
    }

    public function setOutput(Output $output): self
    {
        $this->adapter->setOutput($output);

        return $this;
    }

    public function save(?string $path = null): self
    {
        $this->adapter->save($path);

        return $this;
    }

    public function run(): void
    {
        $this->adapter->run();
    }

    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }
}
