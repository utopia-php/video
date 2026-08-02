<?php

declare(strict_types=1);

namespace Utopia\Video\Format;

use Utopia\Video\Format;

/**
 * H.265, roughly half the bitrate of H.264 at the cost of narrower support.
 */
final class HEVC extends Format
{
    public function video(): string
    {
        return 'libx265';
    }

    public function audio(): string
    {
        return 'aac';
    }

    public function defaults(): array
    {
        return ['-keyint_min', '25', '-g', '250', '-tag:v', 'hvc1'];
    }
}
