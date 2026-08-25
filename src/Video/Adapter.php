<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Adapter\Encoder;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Adapter\Mock;
use Utopia\Video\Adapter\Packager;
use Utopia\Video\Adapter\Probe;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Exception\Unsupported;

/**
 * Shared machinery for every backend.
 *
 * Subclasses declare which binary they drive and which capabilities they
 * implement; everything below is the plumbing they all needed anyway — how to
 * be identified, how to report progress, how to be told which probe to use, and
 * how to fail with a message that says who failed.
 *
 * Nothing here reaches for a binary. Constructing an adapter is a handful of
 * assignments, so wiring one up in a container cannot fail on a missing tool.
 */
abstract class Adapter
{
    use Decimal;

    /** Say nothing at all. */
    public const QUIET = 'quiet';

    /** Only what went wrong. The default. */
    public const ERROR = 'error';

    /** Also what might be wrong. */
    public const WARNING = 'warning';

    /** Also what is being done. */
    public const INFO = 'info';

    /** Also the details of how. */
    public const VERBOSE = 'verbose';

    /** Everything the backend can say. */
    public const DEBUG = 'debug';

    /**
     * Every display level, quietest first.
     *
     * @var list<string>
     */
    public const LEVELS = [
        self::QUIET,
        self::ERROR,
        self::WARNING,
        self::INFO,
        self::VERBOSE,
        self::DEBUG,
    ];

    /**
     * The slug this backend answers to. Matches the factory keys.
     *
     * Annotated because a constant reached through static:: is only as typed as
     * the subclass redeclaring it. PHP 8.3 typed constants would say it in the
     * language; 8.2 is the floor here.
     *
     * @var string
     */
    protected const NAME = '';

    /**
     * The binary it drives, or an empty string when it needs none.
     *
     * @var string
     */
    protected const BINARY = '';

    /**
     * Seconds a command may run. 0 disables the limit.
     *
     * @var int
     */
    protected const TIMEOUT = 0;

    /**
     * How much this backend says about its work unless told otherwise.
     *
     * Errors only, because the interesting output of an encode is the file it
     * wrote, not its commentary. Raise it when something needs explaining:
     * whatever the backend then prints arrives as LOG events.
     *
     * @var string
     */
    protected const LEVEL = self::ERROR;

    protected readonly string $binary;

    protected readonly int $timeout;

    protected readonly int $threads;

    protected readonly string $level;

    /**
     * Who reads media details for this adapter.
     *
     * Deliberately not readonly, and deliberately without a default: an adapter
     * that built its own probe could not be told to share the caller's, which
     * is exactly the bug this replaces.
     */
    protected ?Probe $prober = null;

    /**
     * Where status lines go.
     *
     * Resolved late for the same reason as the probe: a caller replacing the
     * destination after construction has to win, and a default built eagerly
     * would tie every adapter to the terminal whether or not it is on one.
     */
    protected ?Reporter $reporter = null;

    /** @var array<string, list<callable>> */
    protected array $listeners = [];

    /** Remembered answer to available(), which costs a process to work out. */
    private ?bool $available = null;

    /**
     * @param  string|null  $level  One of the constants above; null keeps the default.
     *
     * @throws Unsupported
     */
    public function __construct(
        ?string $binary = null,
        ?int $timeout = null,
        int $threads = 0,
        ?string $level = null,
    ) {
        $this->binary = $binary ?? static::BINARY;
        $this->timeout = $timeout ?? static::TIMEOUT;
        $this->threads = $threads;
        $this->level = $level === null ? static::LEVEL : self::graded($level);
    }

    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * How much this backend says about its work.
     */
    public function level(): string
    {
        return $this->level;
    }

    /**
     * Rejects a level no backend understands, while it is still cheap to say so.
     *
     * @throws Unsupported
     */
    private static function graded(string $level): string
    {
        if (! \in_array($level, self::LEVELS, true)) {
            throw new Unsupported(
                'No display level named "'.$level.'"; expected one of '.\implode(', ', self::LEVELS)
            );
        }

        return $level;
    }

    /**
     * The encoder a name refers to.
     *
     * One factory per capability rather than one returning `Adapter`, so the
     * result is narrow enough to hand straight to a facade without a cast. Names
     * are the ones getName() reports, which is what makes a backend chosen from
     * configuration identifiable again afterwards.
     *
     * @throws Unsupported
     */
    final public static function encoder(
        string $name,
        ?string $binary = null,
        ?int $timeout = null,
        int $threads = 0,
        ?string $level = null,
    ): Encoder {
        return match ($name) {
            FFmpeg::NAME => new FFmpeg($binary, $timeout, $threads, $level),
            Mock::NAME => new Mock(level: $level),
            default => throw new Unsupported('No encoder named "'.$name.'"'),
        };
    }

    /**
     * @throws Unsupported
     */
    final public static function packager(
        string $name,
        ?string $binary = null,
        ?int $timeout = null,
        int $threads = 0,
        ?string $level = null,
    ): Packager {
        return match ($name) {
            FFmpeg::NAME => new FFmpeg($binary, $timeout, $threads, $level),
            Mock::NAME => new Mock(level: $level),
            default => throw new Unsupported('No packager named "'.$name.'"'),
        };
    }

    /**
     * @throws Unsupported
     */
    final public static function probe(
        string $name,
        ?string $binary = null,
        ?int $timeout = null,
        ?string $level = null,
    ): Probe {
        return match ($name) {
            FFprobe::NAME => new FFprobe($binary, $timeout, level: $level),
            Mock::NAME => new Mock(level: $level),
            default => throw new Unsupported('No probe named "'.$name.'"'),
        };
    }

    /**
     * Tell this adapter which probe to read media details with.
     *
     * Called by the facade so that one probe serves the whole job.
     */
    public function setProbe(Probe $probe): void
    {
        $this->prober = $probe;
    }

    /**
     * Tell this adapter where to put its status lines.
     *
     * Called by the facade so that one destination serves the whole job. Pass a
     * Reporter\Silent to keep the library off stdout entirely.
     */
    public function setReporter(Reporter $reporter): void
    {
        $this->reporter = $reporter;
    }

    /**
     * Register a listener. See Adapter\Observable::PROGRESS and ::LOG.
     *
     * @param  callable  $listener
     */
    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    /**
     * Whether this backend's binary can actually be run here.
     *
     * Answered by running the binary, so the answer is remembered: a caller
     * checking before each of a hundred jobs should not pay for a hundred
     * processes to be spawned.
     */
    public function available(): bool
    {
        return $this->available ??= $this->binary === '' || Process::exists($this->binary);
    }

    /**
     * Resolved late, so a probe handed over after construction still wins.
     */
    protected function prober(): Probe
    {
        return $this->prober ??= new FFprobe();
    }

    /**
     * Resolved late, so a reporter handed over after construction still wins.
     */
    protected function reporter(): Reporter
    {
        return $this->reporter ??= new Reporter\Console();
    }

    /**
     * Drop the listeners for one event, or every listener when given nothing.
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

    protected function emit(string $event, mixed $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }

    /**
     * Drop every listener, because a new job is starting.
     */
    protected function forget(): void
    {
        $this->off();
    }

    /**
     * @throws Input
     */
    protected function source(string $path): string
    {
        if (! \is_file($path)) {
            throw new Input($this->getName().': source "'.$path.'" does not exist');
        }

        return $path;
    }

    /**
     * @throws Runtime
     */
    protected function directory(string $path): string
    {
        $path = \rtrim($path, '/');

        if (! \is_dir($path) && ! @\mkdir($path, 0o755, true) && ! \is_dir($path)) {
            throw new Runtime($this->getName().': unable to create "'.$path.'"');
        }

        return $path;
    }

    /**
     * Confirms the backend produced what it was asked for.
     *
     * @throws Runtime
     */
    protected function wrote(string $path): string
    {
        if (! \is_file($path)) {
            throw new Runtime($this->getName().': did not write "'.$path.'"');
        }

        return $path;
    }

    /**
     * Status line for a finished encode, pack, grab or tile.
     *
     * Quiet backends stay silent; everything else reports so a consumer sees
     * the outcome without attaching a LOG listener.
     *
     * @return bool Whether a line was reported.
     */
    protected function reportSuccess(string $message): bool
    {
        if ($this->level === self::QUIET) {
            return false;
        }

        $this->reporter()->success($message);

        return true;
    }

    /**
     * Status line before a command failure is thrown.
     *
     * @return bool Whether a line was reported.
     */
    protected function reportError(string $message): bool
    {
        if ($this->level === self::QUIET) {
            return false;
        }

        $this->reporter()->error($message);

        return true;
    }

    /**
     * Runs a backend command, printing a red line when it fails.
     *
     * @param  list<string>  $command
     * @param  callable(string):void|null  $stdout
     * @param  callable(string):void|null  $stderr
     * @return string The tail of stderr.
     *
     * @throws Runtime
     */
    protected function process(
        array $command,
        ?callable $stdout = null,
        ?callable $stderr = null,
        ?int $timeout = null,
    ): string {
        try {
            return Process::run($command, $stdout, $stderr, $timeout ?? $this->timeout);
        } catch (Runtime $e) {
            $this->reportError($e->getMessage());

            throw $e;
        }
    }

    /**
     * Runs a command and returns its stdout, with the same error reporting.
     *
     * @param  list<string>  $command
     *
     * @throws Runtime
     */
    protected function capture(array $command, ?int $timeout = null): string
    {
        $output = '';

        $this->process($command, Process::collector($output), null, $timeout);

        return $output;
    }
}
