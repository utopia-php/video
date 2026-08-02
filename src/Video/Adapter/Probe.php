<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Info;

/**
 * Reads what a file is without decoding all of it.
 */
interface Probe extends Named
{
    public function read(string $path): Info;

    public function valid(string $path): bool;
}
