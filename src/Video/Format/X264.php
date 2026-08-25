<?php

declare(strict_types=1);

namespace Utopia\Video\Format;

use Utopia\Video\Format;

/**
 * H.264 in AAC, the safest choice for anything that has to play everywhere.
 */
final class X264 extends Format
{
    public function video(): string
    {
        return 'libx264';
    }

    public function audio(): string
    {
        return 'aac';
    }

    public function defaults(): array
    {
        return ['-keyint_min', '25', '-g', '250', '-sc_threshold', '0'];
    }
}
