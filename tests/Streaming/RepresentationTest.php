<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Exception\InvalidArgumentException;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Representations;

class RepresentationTest extends TestCase
{
    public function testResolutionAndBitrates(): void
    {
        $rep = (new Representation())
            ->setResize(1280, 720)
            ->setKiloBitrate(2048)
            ->setAudioKiloBitrate(128);

        $this->assertSame(1280, $rep->getWidth());
        $this->assertSame(720, $rep->getHeight());
        $this->assertSame('1280x720', $rep->getResolution());
        $this->assertSame(2048, $rep->getVideoKiloBitrate());
        $this->assertSame(2048, $rep->getKiloBitrate());
        $this->assertSame(128, $rep->getAudioKiloBitrate());
    }

    public function testInvalidResizeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Representation())->setResize(10, 10);
    }

    public function testCollection(): void
    {
        $a = (new Representation())->setResize(640, 360)->setKiloBitrate(276);
        $b = (new Representation())->setResize(1280, 720)->setKiloBitrate(2048);
        $reps = new Representations([$a, $b]);

        $this->assertCount(2, $reps);
        $this->assertSame($a, $reps->first());
        $this->assertSame($b, $reps->last());
        $this->assertSame([$a, $b], $reps->all());
    }
}
