<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Output;
use Utopia\Video\Package;
use Utopia\Video\Representation;

/**
 * Turns media into a set of segments plus the manifests that describe them.
 *
 * There is no format() here on purpose. A packager that only packages should
 * not be asked about codecs; the ones that can also encode say so by
 * implementing Encoder as well.
 */
interface Packager extends Named, Observable
{
    /**
     * Register an input. Call it more than once to package several already
     * encoded renditions together, tagging each with the rung it represents.
     *
     * The first call after a completed job starts a new one, dropping the
     * settings and listeners the previous job left behind.
     */
    public function open(string $path, ?Representation $as = null): static;

    public function add(Representation ...$representations): static;

    public function output(Output $output): static;

    /**
     * Write the package into a directory and describe what was produced.
     */
    public function pack(string $dir): Package;
}
