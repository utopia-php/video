<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Encoder;
use Utopia\Video\Exception\Input;
use Utopia\Video\Format\X264;
use Utopia\Video\Progress;
use Utopia\Video\Representation;

class EncoderTest extends TestCase
{
    private string $file;

    private string $dir;

    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-encoder-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
        $this->file = $dir.'/source.mp4';
        \file_put_contents($this->file, 'source');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir.'/*') ?: [] as $path) {
            \unlink($path);
        }

        @\rmdir($this->dir);
    }

    private function encoder(?FakeEncoder $adapter = null): Encoder
    {
        return new Encoder($adapter ?? new FakeEncoder(), new FakeProbe());
    }

    public function testDelegatesProbing(): void
    {
        $info = $this->encoder()->probe($this->file);

        $this->assertSame(10.0, $info->duration);
        $this->assertTrue($this->encoder()->valid($this->file));
    }

    public function testReportsWhichBackendIsWorking(): void
    {
        $this->assertSame('fake', $this->encoder()->getName());
    }

    public function testDelegatesStillsAndSheets(): void
    {
        $encoder = $this->encoder();

        $this->assertSame('/out/poster.jpg', $encoder->grab($this->file, '/out/poster.jpg'));
        $this->assertCount(1, $encoder->tile($this->file, '/out')->images());
    }

    public function testEncodePassesTheChainToTheAdapter(): void
    {
        $adapter = new FakeEncoder();
        $format = new X264();
        $rep = new Representation(1280, 720, 2538);

        $path = $this->encoder($adapter)
            ->open($this->file)
            ->format($format)
            ->add($rep)
            ->encode($this->dir.'/video.mp4');

        $this->assertSame($this->dir.'/video.mp4', $path);
        $this->assertSame($this->file, $adapter->opened);
        $this->assertSame($format, $adapter->format);
        $this->assertSame([$rep], $adapter->reps);
    }

    public function testListenersReachTheAdapter(): void
    {
        $adapter = new FakeEncoder();
        $seen = [];

        $this->encoder($adapter)
            ->open($this->file)
            ->add(new Representation(1280, 720, 2538))
            ->on(Encoder::PROGRESS, function (mixed $progress) use (&$seen): void {
                $this->assertInstanceOf(Progress::class, $progress);
                $seen[] = $progress->percent;
            })
            ->encode($this->dir.'/video.mp4');

        $this->assertSame([25.0, 100.0], $seen);
    }

    /**
     * The adapter's own open() drops the previous job, so a reused facade does
     * not carry the last job's rungs into this one.
     */
    public function testOpeningAgainForgetsThePreviousJob(): void
    {
        $adapter = new FakeEncoder();
        $encoder = $this->encoder($adapter);

        $encoder->open($this->file)->add(new Representation(1280, 720, 2538));
        $encoder->open($this->file)->encode($this->dir.'/video.mp4');

        $this->assertSame([], $adapter->reps);
    }

    public function testOpeningAMissingSourceIsAFailure(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('does not exist');

        $this->encoder()->open('/nowhere/nothing.mp4');
    }
}
