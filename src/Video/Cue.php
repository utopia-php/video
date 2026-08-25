<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * One thumbnail on a sprite timeline, addressed as a rectangle inside a sheet.
 *
 * Consumers are free to point the cue at whatever URL they serve the sheet
 * from; only the geometry is fixed.
 */
final class Cue
{
    public function __construct(
        public readonly float $start,
        public readonly float $end,
        public readonly string $file,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /**
     * Renders the cue as a WebVTT block, optionally rewriting the sheet URL.
     */
    public function render(?string $url = null): string
    {
        return self::timestamp($this->start).' --> '.self::timestamp($this->end)."\n"
            .($url ?? $this->file).'#xywh='.$this->x.','.$this->y.','.$this->width.','.$this->height;
    }

    public static function timestamp(float $seconds): string
    {
        $hours = (int) ($seconds / 3600);
        $minutes = (int) (($seconds - ($hours * 3600)) / 60);
        $rest = $seconds - ($hours * 3600) - ($minutes * 60);

        return \sprintf('%02d:%02d:%06.3f', $hours, $minutes, $rest);
    }
}
