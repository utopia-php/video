<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * A codec preset plus the handful of knobs that decide whether the output can
 * be packaged at all.
 *
 * Keyframe placement is the important one: segments can only be cut where a
 * keyframe already exists, so they are forced on a fixed cadence. A packaging
 * job that was not told which cadence to use takes the segment length, so the
 * two cannot silently disagree. Bitrate deliberately lives on the representation
 * instead, because it varies per rung while the codec choice does not.
 *
 * Immutable: every setter returns a modified copy and leaves the receiver
 * untouched, so one instance can be shared across jobs — and across coroutines
 * — without one caller's settings bleeding into another's.
 */
abstract class Format
{
    use Decimal;

    protected string $video;

    protected string $audio;

    protected ?int $crf = null;

    protected ?int $bframes = null;

    protected ?float $keyframe = null;

    /** @var list<string> */
    protected array $params = [];

    public function __construct(?string $video = null, ?string $audio = null)
    {
        $this->video = $video ?? $this->video();
        $this->audio = $audio ?? $this->audio();
    }

    /**
     * Default video codec for this preset.
     */
    abstract public function video(): string;

    /**
     * Default audio codec for this preset.
     */
    abstract public function audio(): string;

    /**
     * Codec specific arguments applied before any user supplied parameters.
     *
     * @return list<string>
     */
    abstract public function defaults(): array;

    /**
     * Output formats this codec can be packaged into.
     *
     * @return list<string>
     */
    public function supports(): array
    {
        return [Output::HLS, Output::DASH, Output::CMAF];
    }

    public function codec(): string
    {
        return $this->video;
    }

    public function sound(): string
    {
        return $this->audio;
    }

    /**
     * Constant rate factor: lower means better quality and a larger file.
     */
    public function crf(int $crf): static
    {
        $copy = clone $this;
        $copy->crf = $crf;

        return $copy;
    }

    /**
     * Number of consecutive B frames the encoder may use.
     */
    public function bframes(int $count): static
    {
        $copy = clone $this;
        $copy->bframes = $count;

        return $copy;
    }

    /**
     * Force a keyframe every N seconds so segments can be cut cleanly.
     *
     * Packaging derives this from the segment length when it is left unset, so
     * it only needs setting to ask for something other than one keyframe per
     * segment.
     */
    public function keyframe(float $seconds): static
    {
        $copy = clone $this;
        $copy->keyframe = $seconds;

        return $copy;
    }

    /**
     * Raw arguments appended after everything this class understands.
     *
     * @param  list<string>  $params
     */
    public function params(array $params): static
    {
        $copy = clone $this;
        $copy->params = $params;

        return $copy;
    }

    public function interval(): ?float
    {
        return $this->keyframe;
    }

    /**
     * Codec arguments for one job, without any stream index suffix.
     *
     * @param  float|null  $cadence  Keyframe interval to fall back on when none
     *                               was configured. Packaging passes the segment
     *                               length, because segments can only be cut
     *                               where a keyframe already is.
     * @return list<string>
     */
    public function build(bool $video = true, bool $audio = true, ?float $cadence = null): array
    {
        $args = [];

        if ($video) {
            $args[] = '-c:v';
            $args[] = $this->video;
        }

        if ($audio) {
            $args[] = '-c:a';
            $args[] = $this->audio;
        }

        if ($video) {
            foreach ($this->defaults() as $default) {
                $args[] = $default;
            }

            if ($this->crf !== null) {
                $args[] = '-crf';
                $args[] = (string) $this->crf;
            }

            if ($this->bframes !== null) {
                $args[] = '-bf';
                $args[] = (string) $this->bframes;
            }

            $keyframe = $this->keyframe ?? $cadence;

            if ($keyframe !== null) {
                $args[] = '-force_key_frames';
                $args[] = 'expr:gte(t,n_forced*'.self::number($keyframe).')';
            }
        }

        foreach ($this->params as $param) {
            $args[] = $param;
        }

        return $args;
    }
}
