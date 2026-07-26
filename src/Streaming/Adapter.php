<?php

declare(strict_types=1);

namespace Utopia\Streaming;

interface Adapter
{
    public function open(string $path): self;

    public function isValid(string $path): bool;

    public function setFormat(Format $format): self;

    public function addRepresentation(Representation $representation): self;

    /**
     * @param  list<Representation>  $reps
     */
    public function addRepresentations(array $reps): self;

    public function setOutput(Output $output): self;

    public function save(?string $path = null): self;

    public function run(): void;
}
