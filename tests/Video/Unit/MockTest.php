<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Video\Adapter\Mock;
use Utopia\Video\Encoder;
use Utopia\Video\Packager;
use Utopia\Tests\Adapters;

/**
 * The whole adapter contract, exercised without a single external tool.
 */
class MockTest extends Adapters
{
    private Mock $adapter;

    protected function available(): bool
    {
        return true;
    }

    private function adapter(): Mock
    {
        return $this->adapter ??= new Mock();
    }

    protected function encoder(): Encoder
    {
        // Given as the probe as well, so nothing here reaches for a binary.
        return new Encoder($this->adapter(), $this->adapter());
    }

    protected function packager(): Packager
    {
        return new Packager($this->adapter(), probe: $this->adapter());
    }

    protected function source(): string
    {
        $path = $this->dir.'/source.mp4';

        if (! \is_file($path)) {
            \file_put_contents($path, 'pretend this is a video');
        }

        return $path;
    }

    protected function names(): array
    {
        return [
            'encoder' => 'mock',
            'packager' => 'mock',
        ];
    }

    /**
     * One class covering several capabilities is the point of the merge: pass it
     * once and it serves every role it can, on either facade.
     */
    public function testOneAdapterServesEveryCapabilityItImplements(): void
    {
        $mock = new Mock();

        $this->assertSame('mock', (new Encoder($mock, $mock))->getName());
        $this->assertSame('mock', (new Packager($mock, probe: $mock))->getName());
    }

    public function testDescribesTheSourceItWasToldToPretend(): void
    {
        $adapter = (new Mock())->pretend(duration: 12.0, width: 1280, height: 720, audio: false);
        $info = (new Encoder($adapter, $adapter))->probe($this->source());

        $this->assertSame(12.0, $info->duration);
        $this->assertSame(1280, $info->width);
        $this->assertFalse($info->hasAudio);
    }
}
