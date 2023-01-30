<?php

namespace Utopia\Tests;


use PHPUnit\Framework\TestCase;
use Streaming\Format\X264;
use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Encoder;
use \FFMpeg\FFMpeg as BFFmpeg;


class FFmpegTest extends TestCase
{
    private static Encoder $encoder;

    public static function setUpBeforeClass(): void
    {
       self::$encoder = new Encoder(new FFmpeg());

    }

    public function testFFmpeg(): void
    {
        $encoder= self::$encoder->open(__DIR__ . '/../resources/sample.mp4');
        $encoder->setFormat(new \Utopia\Streaming\Format\X264());
        var_dump($encoder);
        //self::assertInstanceOf(self::$encoder, BFFmpeg);
    }


}
