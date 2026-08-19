<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Encoder;
use Utopia\Video\Reporter\Silent;
use Utopia\Video\Thumb;

/**
 * The library's coroutine rules, run for real under Swoole.
 *
 * One facade per coroutine, immutable config shared between them: three
 * coroutines pull stills from the same source at the same time, each through
 * its own Encoder, all reading one Thumb. Process yields at proc_open and
 * stream_select under the hooks, so the jobs genuinely interleave.
 *
 * Skipped wherever ext-swoole is not installed; the unit suite covers the
 * immutability half without it.
 */
class CoroutineTest extends Base
{
    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required');
        }

        parent::setUp();
    }

    public function testConcurrentCoroutinesEachWithTheirOwnEncoderDoNotInterfere(): void
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $source = self::video();
        $thumb = (new Thumb())->width(160);
        $written = [];
        $failed = [];

        \Swoole\Coroutine\run(function () use ($source, $thumb, &$written, &$failed): void {
            foreach ([1.0, 3.0, 5.0] as $index => $second) {
                \Swoole\Coroutine::create(function () use ($source, $thumb, $second, $index, &$written, &$failed): void {
                    try {
                        // One facade per coroutine; the Thumb is shared and
                        // configured per job without touching the original.
                        $encoder = new Encoder(reporter: new Silent());

                        $written[$index] = $encoder->grab(
                            $source,
                            $this->dir.'/still-'.$index.'.jpg',
                            $thumb->time($second),
                        );
                    } catch (\Throwable $e) {
                        $failed[$index] = $e->getMessage();
                    }
                });
            }
        });

        $this->assertSame([], $failed);
        $this->assertCount(3, $written);

        foreach ($written as $path) {
            $this->assertWritten($path);
        }

        // The shared Thumb was never written into by any of the three jobs.
        $this->assertNull($thumb->at());
    }
}
