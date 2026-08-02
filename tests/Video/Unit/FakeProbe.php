<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Video\Adapter\Probe;
use Utopia\Video\Info;

class FakeProbe implements Probe
{
    public function read(string $path): Info
    {
        return new Info(duration: 10.0, format: 'mp4', hasVideo: true, hasAudio: true, width: 1920, height: 1080);
    }

    public function valid(string $path): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'fake';
    }
}
