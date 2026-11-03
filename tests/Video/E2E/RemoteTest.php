<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Encoder;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Hls;
use Utopia\Video\Packager;
use Utopia\Video\Representation;
use Utopia\Video\Tile;

/**
 * A source handed over as an http URL is probed, packed and tiled without ever
 * being copied to local disk, which is how a worker reads from object storage.
 *
 * The server is a local stand-in for one: it honours byte ranges and logs them,
 * so these tests also prove ffmpeg seeks rather than streams the file through.
 */
class RemoteTest extends Base
{
    /** @var resource|null */
    private static $server = null;

    private static string $url = '';

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! self::$available) {
            return;
        }

        self::video();

        $probe = \stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            self::fail('no free port for the range server');
        }

        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        self::$log = self::$root.'/ranges-'.\bin2hex(\random_bytes(4)).'.log';
        self::$url = 'http://'.$address;

        $pipes = [];
        $server = \proc_open(
            [PHP_BINARY, '-S', $address, __DIR__.'/serve.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [
                'UTOPIA_SERVE_ROOT' => self::$root,
                'UTOPIA_SERVE_LOG' => self::$log,
                'PHP_CLI_SERVER_WORKERS' => '4',
            ],
        );

        if ($server === false) {
            self::fail('the range server could not be started');
        }

        self::$server = $server;

        for ($i = 0; $i < 50; $i++) {
            $socket = @\stream_socket_client('tcp://'.$address, $code, $message, 0.1);

            if ($socket !== false) {
                \fclose($socket);

                return;
            }

            \usleep(100000);
        }

        self::fail('the range server never started');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }

        self::$server = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        @\unlink(self::$log);
    }

    private function url(): string
    {
        return self::$url.'/source.mp4?X-Amz-Signature=ignored&X-Amz-Expires=60';
    }

    /**
     * @return list<string>
     */
    private function ranges(): array
    {
        return \is_file(self::$log) ? \file(self::$log, FILE_IGNORE_NEW_LINES) ?: [] : [];
    }

    public function testAUrlIsProbed(): void
    {
        $packager = new Packager();

        $this->assertTrue($packager->valid($this->url()));
        $this->assertEqualsWithDelta(8.0, $packager->probe($this->url())->duration, 0.2);
    }

    public function testAUrlThatAnswersNotFoundIsNotUsable(): void
    {
        $this->assertFalse((new Packager())->valid(self::$url.'/nothing.mp4'));
    }

    /**
     * The source's index sits at its end, so a reader that cannot seek would
     * have to download all of it first. Ranged answers prove it seeks.
     */
    public function testALadderIsPackedFromAUrl(): void
    {
        $package = (new Packager())
            ->open($this->url())
            ->format((new X264())->crf(30)->keyframe(2.0)->params(['-preset', 'ultrafast']))
            ->add(new Representation(320, 240, 400, 64))
            ->output((new Hls())->segment(2))
            ->pack($this->dir);

        $total = 0.0;

        foreach ($package->segments() as $segment) {
            $this->assertWritten($segment->path);
        }

        foreach ($package->segments($package->variants()[0]->id) as $segment) {
            $total += $segment->duration;
        }

        $this->assertEqualsWithDelta(8.0, $total, 1.0);
        $this->assertNotEmpty($this->ranges(), 'ffmpeg never asked for a byte range');
        $this->assertSame([], \glob($this->dir.'/*.mp4') ?: [], 'the source was copied into the output');
    }

    public function testATimelineIsTiledFromAUrl(): void
    {
        $sheet = (new Encoder())->tile($this->url(), $this->dir, new Tile());

        $this->assertNotEmpty($sheet->images());
        $this->assertWritten($sheet->images()[0]);
    }

    public function testASingleFileIsEncodedFromAUrl(): void
    {
        $path = (new Encoder())
            ->open($this->url())
            ->format((new X264())->crf(30)->params(['-preset', 'ultrafast']))
            ->encode($this->dir.'/out.mp4');

        $this->assertWritten($path);
    }
}
