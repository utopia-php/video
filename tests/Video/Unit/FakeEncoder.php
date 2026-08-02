<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Video\Adapter\Encoder;
use Utopia\Video\Adapter\Observable;
use Utopia\Video\Format;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Spritesheet;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

class FakeEncoder implements Encoder
{
    public ?string $opened = null;

    public ?Format $format = null;

    /** @var list<Representation> */
    public array $reps = [];

    public int $encoded = 0;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function open(string $path): static
    {
        $this->opened = $path;
        $this->reps = [];
        $this->listeners = [];

        return $this;
    }

    public function valid(string $path): bool
    {
        return true;
    }

    public function format(Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function add(Representation ...$representations): static
    {
        foreach ($representations as $representation) {
            $this->reps[] = $representation;
        }

        return $this;
    }

    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    public function encode(string $path): string
    {
        $this->encoded++;

        foreach ([25.0, 100.0] as $percent) {
            foreach ($this->listeners[Observable::PROGRESS] ?? [] as $listener) {
                $listener(new Progress(percent: $percent));
            }
        }

        $directory = \dirname($path);

        if (! \is_dir($directory)) {
            \mkdir($directory, 0o755, true);
        }

        \file_put_contents($path, 'encoded');

        return $path;
    }

    public function grab(string $path, string $output, ?Thumb $options = null): string
    {
        return $output;
    }

    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet
    {
        return new Spritesheet([$dir.'/sprite1.jpg'], []);
    }

    public function getName(): string
    {
        return 'fake';
    }
}
