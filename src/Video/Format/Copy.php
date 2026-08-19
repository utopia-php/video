<?php

declare(strict_types=1);

namespace Utopia\Video\Format;

use Utopia\Video\Format;

/**
 * Repackage without re-encoding.
 *
 * The source has to already be keyframe aligned on the segment boundary, since
 * nothing here can move a keyframe.
 */
final class Copy extends Format
{
    public function video(): string
    {
        return 'copy';
    }

    public function audio(): string
    {
        return 'copy';
    }

    public function defaults(): array
    {
        return [];
    }

    /**
     * Quality knobs are meaningless without an encoder, so they are ignored.
     */
    public function build(bool $video = true, bool $audio = true, ?float $cadence = null): array
    {
        $args = [];

        if ($video) {
            $args[] = '-c:v';
            $args[] = 'copy';
        }

        if ($audio) {
            $args[] = '-c:a';
            $args[] = 'copy';
        }

        foreach ($this->params as $param) {
            $args[] = $param;
        }

        return $args;
    }
}
