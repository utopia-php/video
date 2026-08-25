<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Adapter\Encoder as EncoderAdapter;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Adapter\Observable;
use Utopia\Video\Adapter\Packager as PackagerAdapter;
use Utopia\Video\Adapter\Probe;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Format\Copy;
use Utopia\Video\Format\X264;

/**
 * Turns a source into an adaptive ladder: segments plus the manifests that
 * describe them.
 *
 * Everything is delegated to an adapter, so the backend is swappable, and the
 * defaults are wired so that `new Packager()` already works. The adapter is only
 * ever known by its interface here, which is what makes replacing it additive:
 * a backend implementing Adapter\Packager slots in without this class changing.
 *
 * Whether the job takes one pass or two follows from what the adapter can do. A
 * packager that also encodes gets the source itself, so a single decode feeds
 * every rung. One that only packages is handed a finished file per rung, encoded
 * first by the encoder adapter. Progress is reported as one run either way.
 *
 * Use Encoder when the job is a single file rather than a ladder.
 */
class Packager
{
    /** Reports how far along a running job is. Receives a Progress. */
    public const PROGRESS = Observable::PROGRESS;

    /** Raw backend output, useful for debugging. Receives a string. */
    public const LOG = Observable::LOG;

    protected readonly PackagerAdapter $adapter;

    /** Encodes the rungs when the packager cannot do it itself. */
    protected readonly EncoderAdapter $encoder;

    protected readonly Probe $prober;

    protected ?string $source = null;

    protected ?Format $encoding = null;

    protected ?Output $target = null;

    /** @var list<Representation> */
    protected array $reps = [];

    /** @var array<string, list<callable>> */
    protected array $listeners = [];

    /**
     * @param  Reporter|null  $reporter  Where status lines go; null keeps them on
     *                                   the terminal.
     */
    public function __construct(
        ?PackagerAdapter $adapter = null,
        ?EncoderAdapter $encoder = null,
        ?Probe $probe = null,
        ?Reporter $reporter = null,
    ) {
        $this->prober = $probe ?? new FFprobe();
        $this->adapter = $adapter ?? new FFmpeg();

        // A packager that can encode is its own encoder, so the common case
        // builds one backend rather than two. Constructing one is a handful of
        // assignments; nothing runs until open().
        $this->encoder = $encoder
            ?? ($this->adapter instanceof EncoderAdapter ? $this->adapter : new FFmpeg());

        // Config is pushed into the adapters rather than passed to their
        // constructors, so an adapter supplied by the caller cannot quietly keep
        // a probe of its own and ignore the one asked for here.
        foreach ([$this->adapter, $this->encoder] as $backend) {
            if ($backend instanceof Adapter) {
                $backend->setProbe($this->prober);

                if ($reporter !== null) {
                    $backend->setReporter($reporter);
                }
            }
        }
    }

    /**
     * Which backend is doing the packaging.
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
     * Start a job. Anything configured by a previous one is discarded, listeners
     * excepted: those belong to this object and outlive the job. See off().
     */
    public function open(string $path): static
    {
        if (! \is_file($path)) {
            throw new Input('Source "'.$path.'" does not exist');
        }

        $this->source = $path;
        $this->encoding = null;
        $this->target = null;
        $this->reps = [];

        // Listeners are deliberately kept. They were registered on this object,
        // not on the job, and clearing them here made on() before open() read
        // perfectly and do nothing. Adapters still drop theirs on open(), which
        // is why attach() re-registers at the point the work starts.

        return $this;
    }

    public function format(Format $format): static
    {
        $this->encoding = $format;

        return $this;
    }

    public function add(Representation ...$representations): static
    {
        foreach ($representations as $representation) {
            $this->reps[] = $representation;
        }

        return $this;
    }

    public function output(Output $output): static
    {
        $this->target = $output;

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

        return $this;
    }

    /**
     * Drop the listeners for one event, or every listener when given nothing.
     *
     * Listeners outlive a job, so this is how a reused Packager stops reporting
     * to the last job's listener before it registers the next one's.
     */
    public function off(?string $event = null): static
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * Encode and package an adaptive ladder.
     */
    public function pack(string $dir): Package
    {
        $source = $this->ready();

        if ($this->target === null) {
            throw new Unsupported('No output format has been set');
        }

        if ($this->reps === []) {
            throw new Unsupported('At least one representation is required');
        }

        $names = [];

        foreach ($this->reps as $rep) {
            if (isset($names[$rep->name])) {
                throw new Input('Representation name "'.$rep->name.'" is used more than once');
            }

            $names[$rep->name] = true;
        }

        if ($this->encoding instanceof Copy) {
            throw new Unsupported(
                'Stream copy cannot build an adaptive package: every video representation '
                .'is filtered to its requested size, and filtering cannot be combined with codec copy',
            );
        }

        $interval = $this->encoding?->interval();

        if ($interval !== null && $interval > $this->target->duration()) {
            throw new Unsupported(
                'A keyframe every '.$interval.'s cannot cut '.$this->target->duration()
                .'s segments; the keyframe interval has to be the segment length or a fraction of it',
            );
        }

        if ($this->adapter instanceof EncoderAdapter) {
            return $this->fused($this->adapter, $source, $this->target, $dir);
        }

        return $this->staged($source, $this->target, $dir);
    }

    /**
     * One pass: the packager encodes and packages together.
     */
    private function fused(
        EncoderAdapter&PackagerAdapter $packager,
        string $source,
        Output $output,
        string $dir,
    ): Package {
        $packager->open($source);

        if ($this->encoding !== null) {
            $packager->format($this->encoding);
        }

        $packager->add(...$this->reps);
        $packager->output($output);
        $this->attach($packager);

        return $packager->pack($dir);
    }

    /**
     * Two stages: encode each rung, then package the results.
     */
    private function staged(string $source, Output $output, string $dir): Package
    {
        // Intermediates live beside the output rather than in the system
        // temporary directory: they can run to gigabytes, and a small tmpfs or a
        // different filesystem would be a poor place to put them.
        $work = \rtrim($dir, '/').'/.staging';

        if (! \is_dir($work) && ! @\mkdir($work, 0o755, true) && ! \is_dir($work)) {
            throw new Runtime('Unable to create "'.$work.'"');
        }

        $total = \count($this->reps);
        $files = [];
        $format = $this->encoding ?? new X264();

        if ($format->interval() === null) {
            $format = $format->keyframe($output->duration());
        }

        try {
            foreach ($this->reps as $position => $rep) {
                $encoder = $this->encoder->open($source);
                $encoder->format($format);
                $encoder->add($rep);

                // Encoding is the slow part, so it owns most of the progress bar.
                $encoder->on(self::PROGRESS, function (mixed $progress) use ($position, $total): void {
                    if (! $progress instanceof Progress) {
                        return;
                    }

                    $share = 90.0 / $total;

                    $this->emit(self::PROGRESS, new Progress(
                        percent: \round(($position * $share) + ($progress->percent * $share / 100), 2),
                        time: $progress->time,
                        frame: $progress->frame,
                        fps: $progress->fps,
                        speed: $progress->speed,
                    ));
                });

                $encoder->on(self::LOG, fn (mixed $line) => $this->emit(self::LOG, $line));

                $files[] = ['path' => $encoder->encode($work.'/'.$rep->name.'.mp4'), 'rep' => $rep];
            }

            foreach ($files as $file) {
                $this->adapter->open($file['path'], $file['rep']);
            }

            $this->adapter->output($output);

            // Only the log is forwarded here, not progress: a progress-capable
            // packager would report its own 0-100 after the encodes already
            // claimed 0-90, and the bar would appear to go backwards.
            $this->adapter->on(self::LOG, fn (mixed $line) => $this->emit(self::LOG, $line));

            $package = $this->adapter->pack($dir);

            $this->emit(self::PROGRESS, new Progress(percent: 100.0));

            return $package;
        } finally {
            foreach ($files as $file) {
                @\unlink($file['path']);
            }

            @\rmdir($work);
        }
    }

    /**
     * Forwards this facade's listeners to whichever adapter is doing the work.
     */
    private function attach(Observable $adapter): void
    {
        foreach ($this->listeners as $event => $listeners) {
            foreach ($listeners as $listener) {
                $adapter->on($event, $listener);
            }
        }
    }

    private function emit(string $event, mixed $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }

    private function ready(): string
    {
        if ($this->source === null) {
            throw new Input('No source has been opened');
        }

        return $this->source;
    }
}
