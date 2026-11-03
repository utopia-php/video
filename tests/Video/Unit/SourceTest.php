<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Source;

class SourceTest extends TestCase
{
    public function testAnHttpOrHttpsUrlIsReachable(): void
    {
        $this->assertTrue(Source::reachable('https://bucket.example/app/video.mp4?X-Amz-Signature=abc&X-Amz-Expires=60'));
        $this->assertTrue(Source::reachable('http://minio:9000/bucket/video.mp4'));
        $this->assertTrue(Source::reachable('HTTPS://EXAMPLE.COM/a.mp4'));
    }

    public function testALocalFileIsReachable(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'utopia-source-');
        $this->assertIsString($path);

        try {
            $this->assertTrue(Source::reachable($path));
        } finally {
            \unlink($path);
        }
    }

    /**
     * ffmpeg speaks many protocols; a source naming any other one could make
     * it read whatever that protocol reaches.
     */
    public function testEveryOtherProtocolIsRefused(): void
    {
        foreach ([
            'file:///etc/passwd',
            'ftp://example.com/video.mp4',
            'concat:a.mp4|b.mp4',
            'pipe:0',
            'tcp://example.com:80',
            'data:video/mp4;base64,AAAA',
        ] as $path) {
            $this->assertFalse(Source::reachable($path), $path);
        }
    }

    public function testAUrlCannotCarryWhitespaceOrControlCharacters(): void
    {
        $this->assertFalse(Source::reachable('https://example.com/a b.mp4'));
        $this->assertFalse(Source::reachable("https://example.com/a.mp4\n-i /etc/passwd"));
        $this->assertFalse(Source::reachable("https://example.com/a.mp4\0"));
        $this->assertFalse(Source::reachable('https://'));
    }

    /**
     * PHP's is_file() follows stream wrappers, so file:///etc/passwd is a file
     * to it, and ffmpeg reads a `concat:` name as a protocol even when a file
     * of that name exists. A local source is a plain path or nothing.
     */
    public function testALocalFileNamedLikeAProtocolIsRefused(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-source-'.\bin2hex(\random_bytes(6));
        \mkdir($dir);
        $cwd = (string) \getcwd();
        \chdir($dir);

        try {
            \file_put_contents('concat:a.mp4', 'x');
            $this->assertTrue(\is_file('concat:a.mp4'));
            $this->assertFalse(Source::reachable('concat:a.mp4'));
            $this->assertTrue(Source::reachable('./concat:a.mp4'), 'a plain relative path is fine');
            $this->assertTrue(Source::reachable($dir.'/concat:a.mp4'), 'and so is an absolute one');
        } finally {
            \unlink($dir.'/concat:a.mp4');
            \chdir($cwd);
            \rmdir($dir);
        }
    }

    public function testAUrlIsNamedWithoutItsQueryString(): void
    {
        $this->assertSame(
            'https://bucket.example/app/video.mp4',
            Source::name('https://bucket.example/app/video.mp4?X-Amz-Credential=a%2Fb&X-Amz-Signature=abc'),
        );
        $this->assertSame('/storage/video.mp4', Source::name('/storage/video.mp4'));
        $this->assertSame('/storage/what?.mp4', Source::name('/storage/what?.mp4'), 'only a URL has a query');
    }

    public function testAMissingLocalPathIsNotReachable(): void
    {
        $this->assertFalse(Source::reachable('/nowhere/nothing.mp4'));
        $this->assertFalse(Source::reachable(''));
    }
}
