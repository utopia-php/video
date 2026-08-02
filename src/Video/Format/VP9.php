<?php

declare(strict_types=1);

namespace Utopia\Video\Format;

use Utopia\Video\Format;
use Utopia\Video\Output;

/**
 * VP9 in Opus, for DASH delivery to browsers.
 *
 * WebM cannot carry an HLS playlist, so this preset refuses HLS and CMAF
 * rather than producing a package no player will accept.
 */
final class VP9 extends Format
{
    public function video(): string
    {
        return 'libvpx-vp9';
    }

    public function audio(): string
    {
        return 'libopus';
    }

    public function defaults(): array
    {
        return [
            '-deadline', 'good',
            '-cpu-used', '2',
            '-row-mt', '1',
            '-tile-columns', '2',
            '-frame-parallel', '1',
            '-keyint_min', '25',
            '-g', '250',
        ];
    }

    public function supports(): array
    {
        return [Output::DASH];
    }
}
