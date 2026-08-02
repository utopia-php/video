<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Console;
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

    /** The slug this backend answers to. Matches the factory keys. */
    protected const NAME = '';

    /** The binary it drives, or an empty string when it needs none. */
    protected const BINARY = '';

    /** Seconds a command may run. 0 disables the limit. */
    protected const TIMEOUT = 0;

    /**
     * How much this backend says about its work unless told otherwise.
     *
     * Errors only, because the interesting output of an encode is the file it
     * wrote, not its commentary. Raise it when something needs explaining:
     * whatever the backend then prints arrives as LOG events.
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

    /** @var array<string, list<callable>> */
    protected array $listeners = [];

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
     */
    public function available(): bool
    {
        return $this->binary === '' || Process::exists($this->binary);
    }

    /**
     * Resolved late, so a probe handed over after construction still wins.
     */
    protected function prober(): Probe
    {
        return $this->prober ??= new FFprobe();
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
        $this->listeners = [];
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
     * Green status line for a finished encode, pack, grab or tile.
     *
     * Quiet backends stay silent; everything else prints so a consumer sees
     * the outcome without attaching a LOG listener.
     *
     * @return bool Whether a line was printed.
     */
    protected function reportSuccess(string $message): bool
    {
        if ($this->level === self::QUIET) {
            return false;
        }

        Console::success($message);

        return true;
    }

    /**
     * Red status line before a command failure is thrown.
     *
     * @return bool Whether a line was printed.
     */
    protected function reportError(string $message): bool
    {
        if ($this->level === self::QUIET) {
            return false;
        }

        Console::error($message);

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

        $this->process($command, function (string $line) use (&$output): void {
            $output .= $line."\n";
        }, null, $timeout);

        return $output;
    }
}
