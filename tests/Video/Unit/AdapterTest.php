<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Adapter;
use Utopia\Video\Adapter\Encoder as EncoderAdapter;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Adapter\Mock;
use Utopia\Video\Adapter\Named;
use Utopia\Video\Adapter\Observable;
use Utopia\Video\Adapter\Packager as PackagerAdapter;
use Utopia\Video\Adapter\Probe;
use Utopia\Video\Encoder;
use Utopia\Video\Exception\Input;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Info;
use Utopia\Video\Output\Hls;
use Utopia\Video\Packager;
use Utopia\Video\Representation;

class AdapterTest extends TestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-adapter-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
        $this->file = $dir.'/source.mp4';
        \file_put_contents($this->file, 'pretend this is a video');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir.'/*') ?: [] as $path) {
            \is_file($path) ? \unlink($path) : @\rmdir($path);
        }

        @\rmdir($this->dir);
    }

    /**
     * The whole point of pushing config in rather than passing it to a
     * constructor: an adapter the caller built themselves must still end up
     * reading through the probe the caller asked for, not one of its own.
     */
    public function testTheProbeGivenToAFacadeIsTheOneAdaptersRead(): void
    {
        $spy = new class () extends Mock {
            public int $reads = 0;

            public function read(string $path): Info
            {
                $this->reads++;

                return parent::read($path);
            }
        };

        $adapter = new FFmpeg();

        // Constructing the facade is what hands the probe over.
        $encoder = new Encoder($adapter, $spy);

        // The adapter reads the source when it opens it, and that read has to
        // go through the probe the facade supplied.
        $adapter->open($this->file);

        $this->assertSame(1, $spy->reads, 'the adapter read through a probe of its own');

        // And the facade's own probe() is the same one.
        $this->assertSame(8.0, $encoder->probe($this->file)->duration);
        $this->assertSame(2, $spy->reads);
    }

    /**
     * Both facades push the probe, so sharing one backend between them leaves it
     * reading through the same probe either way.
     */
    public function testEitherFacadePushesItsProbe(): void
    {
        $spy = new class () extends Mock {
            public int $reads = 0;

            public function read(string $path): Info
            {
                $this->reads++;

                return parent::read($path);
            }
        };

        $adapter = new FFmpeg();

        new Packager($adapter, probe: $spy);

        $adapter->open($this->file);

        $this->assertSame(1, $spy->reads);
    }

    public function testOneAdapterCanServeSeveralCapabilities(): void
    {
        $ffmpeg = new FFmpeg();

        $this->assertInstanceOf(EncoderAdapter::class, $ffmpeg);
        $this->assertInstanceOf(PackagerAdapter::class, $ffmpeg);

        $this->assertSame('ffmpeg', (new Encoder($ffmpeg, new Mock()))->getName());
        $this->assertSame('ffmpeg', (new Packager($ffmpeg, probe: new Mock()))->getName());
    }

    /**
     * A backend declares what it can do by which interfaces it implements, and
     * that is what the facade branches on. FFprobe reads and nothing else.
     */
    public function testABackendDeclaresOnlyWhatItCanDo(): void
    {
        $ffprobe = new FFprobe();

        $this->assertInstanceOf(Probe::class, $ffprobe);
        $this->assertNotInstanceOf(EncoderAdapter::class, $ffprobe);
        $this->assertNotInstanceOf(PackagerAdapter::class, $ffprobe);
    }

    /**
     * A finished job must not leave its inputs or listeners behind, or a second
     * pack reports every log twice and silently reuses the previous output.
     */
    public function testAFinishedJobDoesNotLeakIntoTheNext(): void
    {
        $adapter = new Mock();
        $rep = new Representation(320, 240, 400);

        $package = $adapter
            ->open($this->file, $rep)
            ->output(new Hls())
            ->pack($this->dir);

        $this->assertNotEmpty($package->segments());

        // Opening again starts a new job, so what the last one was told is gone.
        $adapter->open($this->file, $rep);

        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('output format');

        $adapter->pack($this->dir);
    }

    /**
     * Packaging several already encoded renditions is what repeated open() calls
     * are for, so within one job they add rather than reset.
     */
    public function testRepeatedOpensWithinOneJobAddInputs(): void
    {
        $adapter = new class () extends Mock {
            /** @return list<string> */
            public function registered(): array
            {
                return $this->paths();
            }
        };

        $adapter
            ->open($this->file, new Representation(640, 360, 800))
            ->open($this->file, new Representation(1280, 720, 2538));

        $this->assertCount(2, $adapter->registered());

        // A rung tagged on the way in counts as part of the ladder.
        $package = $adapter->output(new Hls())->pack($this->dir);

        $this->assertCount(2, $package->variants());
    }

    public function testFactoriesResolveNamesToBackends(): void
    {
        $this->assertInstanceOf(FFmpeg::class, Adapter::encoder('ffmpeg'));
        $this->assertInstanceOf(FFmpeg::class, Adapter::packager('ffmpeg'));
        $this->assertInstanceOf(FFprobe::class, Adapter::probe('ffprobe'));
        $this->assertInstanceOf(Mock::class, Adapter::encoder('mock'));
        $this->assertInstanceOf(Mock::class, Adapter::packager('mock'));
        $this->assertInstanceOf(Mock::class, Adapter::probe('mock'));
    }

    public function testFactoriesRoundTripWithGetName(): void
    {
        foreach (['ffprobe', 'mock'] as $name) {
            $this->assertSame($name, Adapter::probe($name)->getName());
        }

        foreach (['ffmpeg', 'mock'] as $name) {
            $this->assertSame($name, Adapter::encoder($name)->getName());
            $this->assertSame($name, Adapter::packager($name)->getName());
        }
    }

    /**
     * A backend named in configuration can be handed straight to a facade, and
     * the facade reports the same name back.
     */
    public function testAFactoryResultGoesStraightIntoAFacade(): void
    {
        $this->assertSame('mock', (new Encoder(Adapter::encoder('mock'), Adapter::probe('mock')))->getName());
        $this->assertSame('mock', (new Packager(Adapter::packager('mock'), probe: Adapter::probe('mock')))->getName());
    }

    public function testFactoriesPassBinaryPathsThrough(): void
    {
        $probe = Adapter::probe('ffprobe', '/opt/bin/ffprobe');

        $this->assertInstanceOf(FFprobe::class, $probe);
        $this->assertFalse($probe->available(), 'a made-up path should not be runnable');
    }

    /**
     * Errors only, because the interesting output of an encode is the file it
     * wrote rather than its commentary.
     */
    public function testEveryBackendStartsAtTheDefaultDisplayLevel(): void
    {
        $this->assertSame(Adapter::ERROR, (new FFmpeg())->level());
        $this->assertSame(Adapter::ERROR, (new FFprobe())->level());
        $this->assertSame(Adapter::ERROR, (new Mock())->level());
    }

    public function testTheDisplayLevelCanBeRaisedOrSilenced(): void
    {
        $this->assertSame(Adapter::DEBUG, (new FFmpeg(level: Adapter::DEBUG))->level());
        $this->assertSame(Adapter::QUIET, (new Mock(level: Adapter::QUIET))->level());
    }

    public function testFactoriesPassTheDisplayLevelThrough(): void
    {
        $this->assertSame(
            Adapter::VERBOSE,
            self::levelOf(Adapter::encoder('ffmpeg', level: Adapter::VERBOSE)),
        );
        $this->assertSame(
            Adapter::INFO,
            self::levelOf(Adapter::packager('mock', level: Adapter::INFO)),
        );
        $this->assertSame(
            Adapter::WARNING,
            self::levelOf(Adapter::probe('ffprobe', level: Adapter::WARNING)),
        );

        // Omitting it keeps each backend's own default.
        $this->assertSame(Adapter::ERROR, self::levelOf(Adapter::encoder('ffmpeg')));
    }

    /**
     * The factories return the narrow capability type, and the display level is
     * shared plumbing on the base rather than part of any capability's contract
     * — the same split as setProbe() and available(), which the facades reach
     * through an instanceof check too.
     */
    private static function levelOf(Named $backend): string
    {
        return $backend instanceof Adapter ? $backend->level() : 'not an Adapter';
    }

    /**
     * Rejected while it is still cheap to say so, rather than by the backend
     * halfway through a job.
     */
    public function testAnUnknownDisplayLevelIsRejected(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('No display level named "chatty"');

        new FFmpeg(level: 'chatty');
    }

    /**
     * The level reaches the command, which is the only place it can matter.
     */
    public function testTheDisplayLevelReachesTheCommand(): void
    {
        $adapter = new class (level: Adapter::VERBOSE) extends FFmpeg {
            /** @return list<string> */
            public function command(): array
            {
                return $this->prefix();
            }
        };

        $this->assertSame(
            ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'verbose'],
            \array_slice($adapter->command(), 0, 5),
        );
    }

    /**
     * A quiet backend has nothing to say, but still reports its progress —
     * progress is structured data, not commentary.
     */
    public function testAQuietBackendStillReportsProgress(): void
    {
        $seen = ['progress' => 0, 'log' => 0];

        (new Mock(level: Adapter::QUIET))
            ->open($this->file)
            ->on(Observable::PROGRESS, function () use (&$seen): void {
                $seen['progress']++;
            })
            ->on(Observable::LOG, function () use (&$seen): void {
                $seen['log']++;
            })
            ->encode($this->dir.'/quiet.mp4');

        $this->assertGreaterThan(0, $seen['progress']);
        $this->assertSame(0, $seen['log'], 'a quiet backend should log nothing');
    }

    /**
     * Finished jobs print a status line unless the backend was told to stay quiet.
     */
    public function testStatusLinesFollowTheDisplayLevel(): void
    {
        $quiet = new class (level: Adapter::QUIET) extends Mock {
            /** @var list<bool> */
            public array $printed = [];

            protected function reportSuccess(string $message): bool
            {
                return $this->printed[] = parent::reportSuccess($message);
            }
        };

        $quiet->open($this->file)->encode($this->dir.'/quiet-status.mp4');

        $this->assertSame([false], $quiet->printed);

        $loud = new class () extends Mock {
            /** @var list<bool> */
            public array $printed = [];

            /** @var list<string> */
            public array $messages = [];

            protected function reportSuccess(string $message): bool
            {
                $this->messages[] = $message;

                return $this->printed[] = parent::reportSuccess($message);
            }
        };

        $path = $this->dir.'/loud-status.mp4';
        $loud->open($this->file)->encode($path);

        $this->assertSame([true], $loud->printed);
        $this->assertSame(['mock: encoded '.$path], $loud->messages);
    }

    /**
     * A failed backend command prints before the exception leaves.
     */
    public function testAFailedCommandReportsAnError(): void
    {
        $adapter = new class () extends FFmpeg {
            /** @var list<string> */
            public array $errors = [];

            protected function reportError(string $message): bool
            {
                $this->errors[] = $message;

                return parent::reportError($message);
            }

            public function fail(): void
            {
                $this->process(['false']);
            }
        };

        try {
            $adapter->fail();
            $this->fail('a failing command should throw');
        } catch (Runtime $e) {
            $this->assertNotSame([], $adapter->errors);
            $this->assertStringContainsString('failed with exit code', $adapter->errors[0]);
            $this->assertSame($adapter->errors[0], $e->getMessage());
        }

        $quiet = new class (level: Adapter::QUIET) extends FFmpeg {
            /** @var list<bool> */
            public array $printed = [];

            protected function reportError(string $message): bool
            {
                return $this->printed[] = parent::reportError($message);
            }

            public function fail(): void
            {
                $this->process(['false']);
            }
        };

        try {
            $quiet->fail();
            $this->fail('a failing command should throw');
        } catch (Runtime) {
            $this->assertSame([false], $quiet->printed);
        }
    }

    public function testAnUnknownNameIsRejected(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('No probe named "nope"');

        Adapter::probe('nope');
    }

    public function testAKnownNameForTheWrongCapabilityIsRejected(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('No encoder named "ffprobe"');

        Adapter::encoder('ffprobe');
    }

    /**
     * A backend that drives no binary must not be reported as needing a tool
     * that happens to be missing.
     */
    public function testABackendWithNoBinaryIsAlwaysAvailable(): void
    {
        $this->assertTrue((new Mock())->available());
    }

    public function testAMissingBinaryIsReported(): void
    {
        $this->assertFalse((new FFmpeg('utopia-streaming-nope'))->available());
    }

    public function testEveryAdapterCarriesItsName(): void
    {
        $this->assertSame('ffmpeg', (new FFmpeg())->getName());
        $this->assertSame('ffprobe', (new FFprobe())->getName());
        $this->assertSame('mock', (new Mock())->getName());
    }

    /**
     * Failures say which backend produced them, so a message from two layers
     * down is still traceable.
     */
    public function testFailuresNameTheBackend(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('mock: source "');

        (new Mock())->open($this->dir.'/nothing.mp4');
    }
}
