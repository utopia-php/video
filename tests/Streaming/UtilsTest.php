<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Streaming\Utils;

class UtilsTest extends TestCase
{
    public function testArrayToFFmpegOptConvertsAssociativeMap(): void
    {
        $this->assertSame(
            ['-b:v', '750k', '-s:v', '640x360'],
            Utils::arrayToFFmpegOpt(['b:v' => '750k', 's:v' => '640x360'])
        );
    }

    public function testArrayToFFmpegOptPassesThroughFlatList(): void
    {
        $flat = ['-map', '0:v:0', '-map', '0:a:0'];

        $this->assertSame($flat, Utils::arrayToFFmpegOpt($flat));
    }

    public function testAppendSlash(): void
    {
        $this->assertSame('foo/', Utils::appendSlash('foo'));
        $this->assertSame('foo/', Utils::appendSlash('foo/'));
        $this->assertSame('', Utils::appendSlash(''));
    }
}
