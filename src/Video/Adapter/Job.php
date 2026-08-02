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

    /**
     * Register an input.
     *
     * The first call of a job starts a new one, dropping everything the last
     * job left behind; later calls add another already encoded rendition. Tag
     * an input with the rung it represents when packaging several at once.
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
        if ($this->inputs === []) {
            $this->restart();
        }

        $this->inputs[] = ['path' => $this->source($path), 'rep' => $as];

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
     * Forget the previous job entirely, listeners included.
     */
    protected function restart(): void
    {
        $this->inputs = [];
        $this->reps = [];
        $this->target = null;
        $this->encoding = null;
        $this->forget();
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
