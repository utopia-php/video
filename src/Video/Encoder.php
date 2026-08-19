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
     * Registered on this object rather than on one job, so that open() can drop
     * the adapter's listeners without dropping the caller's.
     *
     * @var array<string, list<callable>>
     */
    protected array $listeners = [];

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
     * Start a job. Anything configured by a previous one is discarded, listeners
     * excepted: those belong to this object and outlive the job. See off().
     */
    public function open(string $path): static
    {
        if (! \is_file($path)) {
            throw new Exception\Input('Source "'.$path.'" does not exist');
        }

        // Passed straight through: the adapter's own open() makes the same
        // promise about dropping the last job's format and rungs, so there is
        // nothing worth buffering here.
        $this->adapter->open($path);

        // Listeners are the exception. The adapter drops its own on open(), so
        // this object's are handed over again afterwards; without that, on()
        // before open() would read perfectly and be silently discarded.
        foreach ($this->listeners as $event => $listeners) {
            foreach ($listeners as $listener) {
                $this->adapter->on($event, $listener);
            }
        }

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
     * Register a listener for PROGRESS or LOG.
     *
     * Order does not matter: a listener registered before open() survives it and
     * hears the job that follows.
     *
     * @param  callable  $listener
     */
    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;
        $this->adapter->on($event, $listener);

        return $this;
    }

    /**
     * Drop the listeners for one event, or every listener when given nothing.
     *
     * Listeners outlive a job, so this is how a reused Encoder stops reporting
     * to the last job's listener before it registers the next one's.
     *
     * A backend that extends Adapter is detached immediately. One that only
     * implements Adapter\Encoder has no off() to call — Observable declares just
     * on(), so that a backend written elsewhere is not asked for more — and it
     * lets go of its copy at the next open() instead.
     */
    public function off(?string $event = null): static
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }

        // The same test the constructor makes before it pushes a probe.
        if ($this->adapter instanceof Adapter) {
            $this->adapter->off($event);
        }

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
