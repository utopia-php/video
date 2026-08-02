<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Video\Adapter\Observable;
use Utopia\Video\Adapter\Packager;
use Utopia\Video\Output;
use Utopia\Video\Package;
use Utopia\Video\Representation;

/**
 * A packager that cannot encode — the shape a backend like Shaka has.
 *
 * It is what proves the staged branch keeps working: nothing shipped implements
 * only Adapter\Packager, so this fake stands in for the one that will.
 */
class FakePackager implements Packager
{
    /** @var list<string> */
    public array $opened = [];

    /** @var list<?Representation> */
    public array $tagged = [];

    /** @var list<Representation> */
    public array $reps = [];

    public ?Output $output = null;

    /** @var array<string, list<callable>> */
    protected array $listeners = [];

    public function open(string $path, ?Representation $as = null): static
    {
        $this->opened[] = $path;
        $this->tagged[] = $as;

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
        $this->output = $output;

        return $this;
    }

    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    public function pack(string $dir): Package
    {
        foreach ($this->listeners[Observable::LOG] ?? [] as $listener) {
            $listener('packing');
        }

        return new Package();
    }

    public function getName(): string
    {
        return 'fake';
    }
}
