<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Format\X264;
use Utopia\Streaming\Output\Hls;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Stream;

class FFmpegTest extends TestCase
{
    public function testFluentApiWiresAdapter(): void
    {
        $adapter = new FFmpeg(['timeout' => 0]);
        $stream = new Stream($adapter);

        $representation = (new Representation())
            ->setVideoKiloBitrate(600)
            ->setAudioKiloBitrate(128)
            ->setResize(640, 360);

        $stream
            ->setFormat(new X264())
            ->addRepresentation($representation)
            ->setOutput(new Hls());

        $this->assertSame($adapter, $stream->getAdapter());
        $this->assertCount(1, $adapter->getRepresentations());
    }
}
