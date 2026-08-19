<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Adapter\Encoder as EncoderAdapter;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Adapter\Observable;
use Utopia\Video\Adapter\Probe;

/**
 * Reads a source and writes a single encoded file, thumbnail or sprite sheet.
 *
 * Everything is delegated to an adapter, so the backend is swappable, and the
 * defaults are wired so that `new Encoder()` already works. The adapter is only
 * ever known by its interface here — anything implementing Adapter\Encoder can
 * take ffmpeg's place without this class changing.
 *
 * Use Packager when the job is an adaptive ladder rather than one file.
 */
class Encoder
{
    /** Reports how far along a running job is. Receives a Progress. */
    public const PROGRESS = Observable::PROGRESS;

    /** Raw backend output, useful for debugging. Receives a string. */
    public const LOG = Observable::LOG;

    protected readonly EncoderAdapter $adapter;

    protected readonly Probe $prober;

    /**
     * @param  Reporter|null  $reporter  Where status lines go; null keeps them on
     *                                   the terminal.
     */
    public function __construct(
        ?EncoderAdapter $adapter = null,
        ?Probe $probe = null,
        ?Reporter $reporter = null,
    ) {
        $this->adapter = $adapter ?? new FFmpeg();
        $this->prober = $probe ?? new FFprobe();

        // Config is pushed into the adapter rather than passed to its
        // constructor, so an adapter supplied by the caller cannot quietly keep
        // a probe of its own and ignore the one asked for here.
        if ($this->adapter instanceof Adapter) {
            $this->adapter->setProbe($this->prober);

            if ($reporter !== null) {
                $this->adapter->setReporter($reporter);
            }
        }
    }

    /**
     * Which backend is doing the encoding.
     */
    public function getName(): string
    {
        return $this->adapter->getName();
    }

    /**
     * Everything that can be learned about a source without decoding it.
     */
    public function probe(string $path): Info
    {
        return $this->prober->read($path);
    }

    /**
     * Whether a source is usable as input.
     */
    public function valid(string $path): bool
    {
        return $this->prober->valid($path);
    }

    /**
     * Write a single still image and return its path.
     */
    public function grab(string $path, string $output, ?Thumb $options = null): string
    {
        return $this->adapter->grab($path, $output, $options);
    }

    /**
     * Write sprite sheets covering the whole timeline.
     */
    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet
    {
        return $this->adapter->tile($path, $dir, $options);
    }

    /**
     * Start a job. Anything configured by a previous one is discarded.
     */
    public function open(string $path): static
    {
        if (! \is_file($path)) {
            throw new Exception\Input('Source "'.$path.'" does not exist');
        }

        // Passed straight through: the adapter's own open() makes the same
        // promise about dropping the last job's format, rungs and listeners, so
        // there is nothing worth buffering here.
        $this->adapter->open($path);

        return $this;
    }

    public function format(Format $format): static
    {
        $this->adapter->format($format);

        return $this;
    }

    public function add(Representation ...$representations): static
    {
        $this->adapter->add(...$representations);

        return $this;
    }

    /**
     * @param  callable  $listener
     */
    public function on(string $event, callable $listener): static
    {
        $this->adapter->on($event, $listener);

        return $this;
    }

    /**
     * Encode to a single file and return the path written.
     */
    public function encode(string $path): string
    {
        return $this->adapter->encode($path);
    }
}
