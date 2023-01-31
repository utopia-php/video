<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Encoder;
use Utopia\Streaming\Stream;

class FFmpegTest extends TestCase
{
    private static Encoder $encoder;

    public static function setUpBeforeClass(): void
    {
        self::$encoder = new Encoder(new FFmpeg([
            'timeout' => 0,
            'ffmpeg.threads' => 12,
        ]
       ));
    }

    public function testFFmpeg(): void
    {

        $representation = (new \Utopia\Streaming\Representation())
            ->setVideoKiloBitrate(6000)
            ->setAudioKiloBitrate(128)
            ->setResize(1080, 768);

        (new Stream(self::$encoder))
            ->open(__DIR__.'/../resources/sample.mp4')
            ->setFormat(new \Utopia\Streaming\Format\X264())
            ->addRepresentation($representation)
            ->setOutput(new \Utopia\Streaming\Output\Hls())
            ->run()
        ;


    }
}
