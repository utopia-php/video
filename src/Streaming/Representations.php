<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Representation>
 */
class Representations implements Countable, IteratorAggregate
{
    /** @var list<Representation> */
    private array $items = [];

    /**
     * @param  list<Representation>  $reps
     */
    public function __construct(array $reps = [])
    {
        foreach ($reps as $representation) {
            $this->add($representation);
        }
    }

    public function add(Representation $representation): self
    {
        $this->items[] = $representation;

        return $this;
    }

    public function first(): ?Representation
    {
        return $this->items[0] ?? null;
    }

    public function last(): ?Representation
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[array_key_last($this->items)];
    }

    /**
     * @return list<Representation>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, Representation>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
