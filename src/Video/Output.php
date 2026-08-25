<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * How a package should be laid out on disk.
 *
 * These objects only ever carry what the caller asked for. Nothing discovered
 * about the source is written back into them, and every setter returns a
 * modified copy rather than touching the receiver, so one instance can be
 * shared across as many jobs — and coroutines — as you like.
 */
abstract class Output
{
    use Decimal;

    public const HLS = 'hls';

    public const DASH = 'dash';

    public const CMAF = 'cmaf';

    protected float $segment = 6.0;

    protected bool $manifests = true;

    protected string $name = 'stream';

    /** @var list<string> */
    protected array $params = [];

    /**
     * Which of the constants above this output represents.
     */
    abstract public function type(): string;

    /**
     * Target segment length in seconds.
     */
    public function segment(float $seconds): static
    {
        $copy = clone $this;
        $copy->segment = $seconds;

        return $copy;
    }

    /**
     * Whether to keep the playlist files after they have been read.
     *
     * Segment metadata is returned either way; this only decides whether the
     * manifests themselves survive on disk.
     */
    public function manifests(bool $write): static
    {
        $copy = clone $this;
        $copy->manifests = $write;

        return $copy;
    }

    /**
     * Base name shared by every artifact this job writes.
     */
    public function name(string $base): static
    {
        $name = Name::label($base, 'Output name');

        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    /**
     * Raw muxer arguments appended to the generated command.
     *
     * @param  list<string>  $params
     */
    public function params(array $params): static
    {
        $copy = clone $this;
        $copy->params = $params;

        return $copy;
    }

    public function duration(): float
    {
        return $this->segment;
    }

    public function keeps(): bool
    {
        return $this->manifests;
    }

    public function base(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function extra(): array
    {
        return $this->params;
    }
}
