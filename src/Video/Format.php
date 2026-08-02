<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * A codec preset plus the handful of knobs that decide whether the output can
 * be packaged at all.
 *
 * Keyframe placement is the important one: segments can only be cut where a
 * keyframe already exists, so callers force them on a fixed cadence. Bitrate
 * deliberately lives on the representation instead, because it varies per rung
 * while the codec choice does not.
 */
abstract class Format
{
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
        $this->crf = $crf;

        return $this;
    }

    /**
     * Number of consecutive B frames the encoder may use.
     */
    public function bframes(int $count): static
    {
        $this->bframes = $count;

        return $this;
    }

    /**
     * Force a keyframe every N seconds so segments can be cut cleanly.
     */
    public function keyframe(float $seconds): static
    {
        $this->keyframe = $seconds;

        return $this;
    }

    /**
     * Raw arguments appended after everything this class understands.
     *
     * @param  list<string>  $params
     */
    public function params(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    public function interval(): ?float
    {
        return $this->keyframe;
    }

    /**
     * Codec arguments for one job, without any stream index suffix.
     *
     * @return list<string>
     */
    public function build(bool $video = true, bool $audio = true): array
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

            if ($this->keyframe !== null) {
                $args[] = '-force_key_frames';
                $args[] = 'expr:gte(t,n_forced*'.self::number($this->keyframe).')';
            }
        }

        foreach ($this->params as $param) {
            $args[] = $param;
        }

        return $args;
    }

    protected static function number(float $value): string
    {
        if ((float) (int) $value === $value) {
            return (string) (int) $value;
        }

        return \rtrim(\rtrim(\sprintf('%.3F', $value), '0'), '.');
    }
}
