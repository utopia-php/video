<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Runtime;

/**
 * The shared answer to "can this file be used at all?".
 *
 * A trait rather than a method on the base class, so that backends which never
 * read media — subtitle conversion, for one — do not inherit a check that would
 * reach for a probe they have no use for.
 *
 * @internal
 */
trait Reads
{
    public function valid(string $path): bool
    {
        if (! \is_file($path)) {
            return false;
        }

        try {
            $info = $this->prober()->read($path);
        } catch (Input|Runtime) {
            return false;
        }

        return $info->duration > 0 && ($info->hasVideo || $info->hasAudio);
    }

    abstract protected function prober(): Probe;
}
