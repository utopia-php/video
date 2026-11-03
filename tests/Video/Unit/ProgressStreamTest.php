<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\Mock;
use Utopia\Video\Encoder;
use Utopia\Video\Progress;

/**
 * How ffmpeg's -progress stream turns into PROGRESS events, driven by a stand-in
 * binary that prints exactly the lines ffmpeg does, N/A included.
 */
class ProgressStreamTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir().'/utopia-progress-'.\bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $name) {
            if (\is_file($this->dir.'/'.$name)) {
                \unlink($this->dir.'/'.$name);
            }
        }

        @\rmdir($this->dir);
    }

    /**
     * A stand-in ffmpeg: prints the given progress blocks on stdout, then writes
     * its last argument, which is where the real one puts its output.
     *
     * @param  list<string>  $lines
     */
    private function binary(array $lines): string
    {
        $path = $this->dir.'/ffmpeg';
        \file_put_contents($path, "#!/bin/sh\n"
            ."cat <<'EOF'\n".\implode("\n", $lines)."\nEOF\n"
            .'for last; do :; done; printf x > "$last"'."\n");
        \chmod($path, 0o755);

        return $path;
    }

    /**
     * @param  list<string>  $lines
     * @return list<float>
     */
    private function percentages(array $lines): array
    {
        $source = $this->dir.'/source.mp4';
        \file_put_contents($source, 'source');
        $probe = (new Mock())->pretend(duration: 10.0);
        $seen = [];

        (new Encoder(new FFmpeg($this->binary($lines)), $probe))
            ->on(Encoder::PROGRESS, function (Progress $progress) use (&$seen): void {
                $seen[] = $progress->percent;
            })
            ->open($source)
            ->encode($this->dir.'/out.mp4');

        return $seen;
    }

    public function testABlockWithoutATimestampDoesNotSendProgressBackToTheStart(): void
    {
        $seen = $this->percentages([
            'out_time_us=2000000', 'out_time_ms=2000000', 'progress=continue',
            'out_time_us=7000000', 'out_time_ms=7000000', 'progress=continue',
            'out_time_us=N/A', 'out_time_ms=N/A', 'progress=continue',
            'out_time_us=9900000', 'out_time_ms=9900000', 'progress=end',
        ]);

        $this->assertSame([20.0, 20.0, 70.0, 70.0, 99.0, 99.0, 100.0], $seen);
    }

    public function testANegativeTimestampIsIgnoredToo(): void
    {
        $seen = $this->percentages([
            'out_time_us=-9223372036854775807', 'progress=continue',
            'out_time_us=5000000', 'progress=end',
        ]);

        $this->assertSame([50.0, 100.0], $seen);
    }
}
