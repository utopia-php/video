<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Format\X264;
use Utopia\Video\Output;
use Utopia\Video\Package;
use Utopia\Video\Packager;
use Utopia\Video\Process;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Written;

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

    /**
     * Thirty seconds, long enough that ffmpeg reports progress several times
     * before it finishes a 720p rung.
     */
    protected static function long(): string
    {
        $path = self::$root.'/long.mp4';

        if (\is_file($path)) {
            return $path;
        }

        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=30:size=640x480:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=30',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            '-c:a', 'aac', '-shortest',
            $path,
        ], timeout: 120);

        return $path;
    }

    /**
     * Packs the long source while a listener ships every finished segment and
     * deletes it, the way a worker with little scratch disk would.
     *
     * @return array{package: Package, during: int, peak: int, shipped: array<string, int>, progress: list<float>, after: int}
     *                                                                                      during: segments reported before ffmpeg said it was done;
     *                                                                                      peak: most segment files on disk at once
     */
    protected function ship(Output $output): array
    {
        $ended = false;
        $during = 0;
        $peak = 0;
        $shipped = [];
        $progress = [];
        $events = [];
        $dir = $this->dir;

        $package = (new Packager())
            ->open(self::long())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'slow']))
            ->add(new Representation(1280, 720, 2500, 64))
            ->output($output->segment(2))
            ->on(Packager::PROGRESS, function (Progress $report) use (&$ended, &$progress, &$events): void {
                $ended = $ended || $report->percent >= 100.0;
                $progress[] = $report->percent;
                $events[] = $report->percent < 100.0 ? 'progress' : 'done';
            })
            ->on(Packager::SEGMENT, function (Written $written) use (&$ended, &$during, &$peak, &$shipped, &$events, $dir): void {
                $events[] = 'segment';
                $peak = \max($peak, \count(self::segments($dir)));
                $during += $ended ? 0 : 1;
                $shipped[$written->file] = $written->size;
                \unlink($written->path);
            })
            ->pack($this->dir);

        // Progress reported once a segment had already left: shipping must not
        // swallow or stall the progress stream a worker drives its status from.
        $first = \array_search('segment', $events, true);
        $after = $first === false ? 0 : \count(\array_keys(\array_slice($events, $first), 'progress', true));

        return ['package' => $package, 'during' => $during, 'peak' => $peak, 'shipped' => $shipped, 'progress' => $progress, 'after' => $after];
    }

    /**
     * What ship() promises, for any output.
     *
     * @param  array{package: Package, during: int, peak: int, shipped: array<string, int>, progress: list<float>, after: int}  $run
     */
    protected function assertShippedWhileRunning(array $run): void
    {
        $segments = $run['package']->segments();

        $this->assertGreaterThan(0, $run['during'], 'nothing left the disk before ffmpeg finished');
        $this->assertLessThan(\count($segments), $run['peak'], 'every segment was on disk at once');
        $this->assertSame([], self::segments($this->dir));
        $this->assertCount(\count($segments), $run['shipped']);
        $this->assertSame(\array_sum($run['shipped']), $run['package']->size());

        $sorted = $run['progress'];
        \sort($sorted);
        $this->assertSame($sorted, $run['progress'], 'progress went backwards while segments shipped');
        $this->assertSame(100.0, \end($run['progress']), 'progress never reached 100');
        $this->assertGreaterThan(0, $run['after'], 'no progress was reported once segments began to ship');
    }

    /**
     * Segment files in a directory. Not glob with braces: GLOB_BRACE does not
     * exist on musl, which is what the test image runs.
     *
     * @return list<string>
     */
    protected static function segments(string $dir): array
    {
        return \array_values(\array_filter(
            \scandir($dir) ?: [],
            static fn (string $name): bool => \preg_match('/\.(ts|m4s|mp4)$/', $name) === 1,
        ));
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
