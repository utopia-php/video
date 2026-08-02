<?php

declare(strict_types=1);

namespace Utopia\Video\Arguments;

use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Output\Cmaf as CmafOutput;

/**
 * The dash muxer asked to write an HLS playlist tree alongside its manifest.
 *
 * Both descriptions end up pointing at the same fragmented MP4 segments, which
 * is the whole reason to package this way.
 *
 * @internal
 */
final class Cmaf extends Dash
{
    protected function muxer(): array
    {
        $cmaf = $this->output;

        if (! $cmaf instanceof CmafOutput) {
            throw new Unsupported('Expected a CMAF output');
        }

        return [
            // The HLS side of the muxer is skipped unless segments are MP4.
            '-dash_segment_type', 'mp4',
            '-hls_playlist', '1',
            '-hls_master_name', $cmaf->masterFile(),
        ];
    }
}
