<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Process;

/**
 * Shared fixtures for the tests that drive a real encoder.
 *
 * Sources are generated rather than committed so the suite stays small and the
 * fixtures are described by the code that needs them.
 */
abstract class Base extends TestCase
{
    protected static string $root;

    protected static bool $available = false;

    protected string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$available = Process::exists('ffmpeg') && Process::exists('ffprobe');

        if (! self::$available) {
            return;
        }

        self::$root = \sys_get_temp_dir().'/utopia-streaming-e2e';

        if (! \is_dir(self::$root)) {
            \mkdir(self::$root, 0o755, true);
        }

        self::video();
        self::silent();
        self::audio();
    }

    protected function setUp(): void
    {
        if (! self::$available) {
            $this->markTestSkipped('ffmpeg and ffprobe are required');
        }

        $this->dir = self::$root.'/'.\bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            self::remove($this->dir);
        }
    }

    /**
     * Eight seconds of colour bars and a tone, with a keyframe every two
     * seconds so it can be cut into segments.
     */
    protected static function video(): string
    {
        $path = self::$root.'/source.mp4';

        if (\is_file($path)) {
            return $path;
        }

        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=8:size=640x480:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=8',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            '-c:a', 'aac', '-shortest',
            '-metadata', 'title=Utopia Test',
            $path,
        ], timeout: 120);

        return $path;
    }

    protected static function silent(): string
    {
        $path = self::$root.'/silent.mp4';

        if (\is_file($path)) {
            return $path;
        }

        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=4:size=320x240:rate=25',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            $path,
        ], timeout: 120);

        return $path;
    }

    protected static function audio(): string
    {
        $path = self::$root.'/audio.m4a';

        if (\is_file($path)) {
            return $path;
        }

        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=6',
            '-c:a', 'aac',
            $path,
        ], timeout: 120);

        return $path;
    }

    protected static function remove(string $dir): void
    {
        foreach (\glob($dir.'/*') ?: [] as $path) {
            \is_dir($path) ? self::remove($path) : \unlink($path);
        }

        @\rmdir($dir);
    }

    /**
     * Confirms a file exists and is not empty.
     */
    protected function assertWritten(string $path, string $message = ''): void
    {
        $this->assertFileExists($path, $message);
        $this->assertGreaterThan(0, (int) \filesize($path), $message !== '' ? $message : $path.' is empty');
    }
}
