<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Encoder;
use Utopia\Video\Output\Hls;
use Utopia\Video\Packager;
use Utopia\Video\Process;
use Utopia\Video\Representation;
use Utopia\Tests\Adapters;

/**
 * The shared adapter contract, run against the real tools.
 *
 * Everything here also runs under Adapter\Mock in the unit suite, so a failure
 * that appears only in this class is ffmpeg's behaviour rather than the
 * library's logic.
 */
class FFmpegTest extends Adapters
{
    protected function available(): bool
    {
        return Process::exists('ffmpeg') && Process::exists('ffprobe');
    }

    protected function encoder(): Encoder
    {
        return new Encoder();
    }

    protected function packager(): Packager
    {
        return new Packager();
    }

    protected function source(): string
    {
        $path = $this->dir.'/source.mp4';

        if (! \is_file($path)) {
            Process::run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=duration=6:size=640x480:rate=25',
                '-f', 'lavfi', '-i', 'sine=duration=6',
                '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
                '-force_key_frames', 'expr:gte(t,n_forced*2)',
                '-c:a', 'aac', '-shortest',
                $path,
            ], timeout: 120);
        }

        return $path;
    }

    protected function names(): array
    {
        return [
            'encoder' => 'ffmpeg',
            'packager' => 'ffmpeg',
        ];
    }

    /**
     * Both facades take the same backend, so an application that encodes and
     * packages can build one adapter and hand it to each.
     */
    public function testOneAdapterCanServeBothFacades(): void
    {
        $ffmpeg = new FFmpeg();
        $encoder = new Encoder($ffmpeg);
        $packager = new Packager($ffmpeg);

        $this->assertSame('ffmpeg', $encoder->getName());
        $this->assertSame('ffmpeg', $packager->getName());

        // And the shared adapter still does real work through either of them.
        $this->assertGreaterThan(0, $encoder->probe($this->source())->duration);
        $this->assertGreaterThan(0, $packager->probe($this->source())->duration);
    }

    /**
     * The display level decides how much ffmpeg says, and whatever it says
     * arrives as LOG events. A clean encode at the default level says nothing.
     */
    public function testTheDisplayLevelDecidesHowMuchIsReported(): void
    {
        $lines = function (?string $level): int {
            $count = 0;

            (new Encoder(new FFmpeg(level: $level)))
                ->open($this->source())
                ->format($this->format())
                ->add(new Representation(320, 240, 400, 64))
                ->on(Encoder::LOG, function () use (&$count): void {
                    $count++;
                })
                ->encode($this->dir.'/level-'.($level ?? 'default').'.mp4');

            return $count;
        };

        $quiet = $lines(FFmpeg::QUIET);
        $default = $lines(null);
        $verbose = $lines(FFmpeg::VERBOSE);

        $this->assertSame(0, $quiet, 'a quiet encoder should say nothing');
        $this->assertSame(0, $default, 'a clean encode has no errors to report');
        $this->assertGreaterThan(0, $verbose, 'a verbose encoder should explain itself');
    }

    /**
     * ffmpeg packages and encodes, so a default Packager takes the single-pass
     * route rather than writing intermediates first.
     */
    public function testTheDefaultPackagerLeavesNoIntermediatesBehind(): void
    {
        $this->packager()
            ->open($this->source())
            ->format($this->format())
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segment(2))
            ->pack($this->dir.'/fused');

        $this->assertDirectoryDoesNotExist($this->dir.'/fused/.staging');
    }
}
