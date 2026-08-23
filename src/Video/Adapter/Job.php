<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Exception\Input;
use Utopia\Video\Format;
use Utopia\Video\Output;
use Utopia\Video\Representation;

/**
 * The chain of settings that make up one encode or packaging job.
 *
 * Encoding and packaging accumulate the same things — inputs, a ladder, a
 * format, somewhere to put the result — so they register them the same way.
 * Keeping the rule in one place is also what stops a finished job leaking into
 * the next one, which is easy to get wrong when each backend resets its own
 * state.
 *
 * @internal
 */
trait Job
{
    /** @var list<array{path: string, rep: ?Representation}> */
    protected array $inputs = [];

    /** @var list<Representation> */
    protected array $reps = [];

    protected ?Output $target = null;

    protected ?Format $encoding = null;

    /** Whether a job is being built up rather than waiting to be started. */
    protected bool $started = false;

    /**
     * Register an input.
     *
     * The first call of a job starts it; later calls add another already encoded
     * rendition. Tag an input with the rung it represents when packaging several
     * at once.
     */
    public function open(string $path, ?Representation $as = null): static
    {
        $this->register($path, $as);

        return $this;
    }

    /**
     * The registration itself, kept separate so a backend that needs to do more
     * on open() can reuse it. A trait method cannot be reached with parent::.
     */
    protected function register(string $path, ?Representation $as = null): void
    {
        // Validated before anything is remembered, so a source that turns out
        // not to exist leaves the chain exactly as it was.
        $input = ['path' => $this->source($path), 'rep' => $as];

        if (! $this->started) {
            // A job begins. Only the listeners are dropped: a finished job
            // clears its own configuration, so whatever is set here was set by
            // the caller for this job, and clearing it would silently discard a
            // ladder described before the source was opened.
            $this->forget();
            $this->started = true;
        }

        $this->inputs[] = $input;

        if ($as !== null) {
            $this->reps[] = $as;
        }
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

    public function format(Format $format): static
    {
        $this->encoding = $format;

        return $this;
    }

    /**
     * The job is over, whether it succeeded or not.
     *
     * Called by every terminal, so that a job clears up after itself rather
     * than leaving the next open() to guess what belonged to whom.
     */
    protected function done(): void
    {
        $this->inputs = [];
        $this->reps = [];
        $this->target = null;
        $this->encoding = null;
        $this->started = false;
    }

    /**
     * The path opened first, which is the source unless several were given.
     *
     * @throws Input
     */
    protected function opened(): string
    {
        $first = $this->inputs[0]['path'] ?? null;

        if ($first === null) {
            throw new Input($this->getName().': no source has been opened');
        }

        return $first;
    }

    /**
     * Every registered input path, in the order they were opened.
     *
     * @return list<string>
     */
    protected function paths(): array
    {
        return \array_map(
            static fn (array $input): string => $input['path'],
            $this->inputs,
        );
    }

    abstract public function getName(): string;

    abstract protected function source(string $path): string;

    abstract protected function forget(): void;
}
