<?php

declare(strict_types=1);

namespace Utopia\Video\Arguments;

use Utopia\Video\Arguments;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format;
use Utopia\Video\Info;
use Utopia\Video\Output;
use Utopia\Video\Output\Dash as DashOutput;
use Utopia\Video\Representation;

/**
 * One dash muxer invocation covering every rung.
 *
 * @internal
 */
class Dash extends Arguments
{
    /**
     * @param  list<Representation>  $reps
     */
    public function __construct(
        Info $info,
        Format $format,
        array $reps,
        Output $output,
        string $dir,
        int $inputs = 1,
    ) {
        parent::__construct($info, $format, $reps, $output, $dir, $inputs);

        $this->shaped();
    }

    /**
     * Every rung of a DASH ladder has to be the same shape.
     *
     * All video rungs go into one adaptation set, and a set may only hold
     * representations that are alternatives of each other — so the muxer refuses
     * a set whose members disagree on aspect ratio. It only says so once the
     * command is already running ("Conflicting stream aspect ratios values"),
     * with no mention of which rungs clashed, so the check happens here instead.
     * HLS has no such rule and is left alone.
     */
    private function shaped(): void
    {
        $shapes = [];

        foreach ($this->rungs() as $rep) {
            $shapes[self::shape($rep->width, $rep->height)][] = $rep->name.' ('.$rep->resolution().')';
        }

        if (\count($shapes) < 2) {
            return;
        }

        $described = [];

        foreach ($shapes as $shape => $names) {
            $described[] = $shape.' → '.\implode(', ', $names);
        }

        throw new Unsupported(
            \strtoupper($this->output->type()).' needs every rung to share one aspect ratio; got '
            .\implode('; ', $described)
            .'. Choose sizes that keep the same shape, or package as HLS, which allows a mix.',
        );
    }

    private static function shape(int $width, int $height): string
    {
        $a = $width;
        $b = $height;

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        $divisor = $a === 0 ? 1 : $a;

        return \intdiv($width, $divisor).':'.\intdiv($height, $divisor);
    }

    public function build(): array
    {
        $dash = $this->output;

        if (! $dash instanceof DashOutput) {
            throw new Unsupported('Expected a DASH output');
        }

        $args = $this->maps();

        foreach ($this->streams() as $arg) {
            $args[] = $arg;
        }

        $args[] = '-f';
        $args[] = 'dash';
        $args[] = '-seg_duration';
        $args[] = self::number($dash->duration());
        $args[] = '-use_template';
        $args[] = $dash->templated() ? '1' : '0';
        $args[] = '-use_timeline';
        $args[] = $dash->timelined() ? '1' : '0';
        $args[] = '-window_size';
        $args[] = '0';
        $args[] = '-init_seg_name';
        $args[] = $dash->initPattern();
        $args[] = '-media_seg_name';
        $args[] = $dash->mediaPattern();
        $args[] = '-adaptation_sets';
        $args[] = $dash->adaptations(\count($this->rungs()), \count($this->sound()));

        foreach ($this->muxer() as $arg) {
            $args[] = $arg;
        }

        foreach ($dash->extra() as $param) {
            $args[] = $param;
        }

        return $args;
    }

    public function target(): string
    {
        $dash = $this->output;

        if (! $dash instanceof DashOutput) {
            throw new Unsupported('Expected a DASH output');
        }

        return $this->path($dash->manifestFile());
    }

    /**
     * Hook for subclasses that need extra muxer options.
     *
     * @return list<string>
     */
    protected function muxer(): array
    {
        return [];
    }
}
