<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Alchemy\BinaryDriver\Configuration;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\FFProbe;
use FFMpeg\Media\AbstractMediaType;
use PHPUnit\Framework\TestCase;
use Utopia\Streaming\CommandBuilder;
use Utopia\Streaming\Format\X264;

class CommandBuilderTest extends TestCase
{
    public function testBuildAssemblesInputFilterThreadsAndOutput(): void
    {
        $driver = $this->createMock(FFMpegDriver::class);
        $driver->method('getConfiguration')->willReturn(new Configuration([
            'ffmpeg.threads' => 4,
        ]));

        $ffprobe = $this->createMock(FFProbe::class);

        $media = $this->getMockBuilder(AbstractMediaType::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPathfile', 'getFFMpegDriver', 'getFFProbe', 'filters'])
            ->getMock();
        $media->method('getPathfile')->willReturn('/input/sample.mp4');
        $media->method('getFFMpegDriver')->willReturn($driver);
        $media->method('getFFProbe')->willReturn($ffprobe);

        $commands = (new CommandBuilder($media, new X264(), ['ss' => '0']))
            ->build(['-c:v', 'libx264', '-f', 'hls'], '/out/video_%v_720p.m3u8');

        $this->assertSame([
            '-ss',
            '0',
            '-y',
            '-i',
            '/input/sample.mp4',
            '-c:v',
            'libx264',
            '-f',
            'hls',
            '-threads',
            '4',
            '/out/video_%v_720p.m3u8',
        ], $commands);
    }
}
