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
use Utopia\Video\Reporter;
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
        // Asked of the class rather than of an instance: the claim is about what
        // FFprobe declares, and an instanceof against a known type is a claim
        // about nothing that analysis will happily prove for you.
        $implements = \class_implements(FFprobe::class);

        $this->assertContains(Probe::class, $implements);
        $this->assertNotContains(EncoderAdapter::class, $implements);
        $this->assertNotContains(PackagerAdapter::class, $implements);
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
     * The chain reads as well in one order as the other, so a ladder described
     * before the source was opened has to survive the opening. It is the promise
     * open() already makes about listeners: what was set on the adapter belongs
     * to the caller, and only the previous job's leftovers are swept away.
     */
    public function testConfigurationSetBeforeOpeningSurvivesIt(): void
    {
        $adapter = new Mock();

        $package = $adapter
            ->add(new Representation(320, 240, 400))
            ->output(new Hls())
            ->open($this->file)
            ->pack($this->dir);

        $this->assertCount(1, $package->variants());
        $this->assertNotEmpty($package->segments());
    }

    /**
     * The other half of the same promise: configuring the next job before
     * opening it must not inherit the finished one's ladder.
     */
    public function testANewJobConfiguredBeforeOpeningStartsClean(): void
    {
        $adapter = new Mock();

        $adapter
            ->open($this->file)
            ->add(new Representation(320, 240, 400))
            ->output(new Hls())
            ->pack($this->dir);

        $package = $adapter
            ->add(new Representation(640, 480, 800))
            ->output(new Hls())
            ->open($this->file)
            ->pack($this->dir);

        $this->assertCount(1, $package->variants());
        $this->assertSame(640, $package->variants()[0]->width);
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
     * Finished jobs report a status line unless the backend was told to stay quiet.
     */
    public function testStatusLinesFollowTheDisplayLevel(): void
    {
        $reporter = new Recorder();

        $quiet = new Mock(level: Adapter::QUIET);
        $quiet->setReporter($reporter);
        $quiet->open($this->file)->encode($this->dir.'/quiet-status.mp4');

        $this->assertSame([], $reporter->successes, 'a quiet backend should say nothing');

        $path = $this->dir.'/loud-status.mp4';
        $loud = new Mock();
        $loud->setReporter($reporter);
        $loud->open($this->file)->encode($path);

        $this->assertSame(['mock: encoded '.$path], $reporter->successes);
    }

    /**
     * A failed backend command says so before the exception leaves.
     */
    public function testAFailedCommandReportsAnError(): void
    {
        $reporter = new Recorder();

        $adapter = new class () extends FFmpeg {
            public function fail(): void
            {
                $this->process(['false']);
            }
        };

        $adapter->setReporter($reporter);

        try {
            $adapter->fail();
            $this->fail('a failing command should throw');
        } catch (Runtime $e) {
            $this->assertCount(1, $reporter->errors);
            $this->assertStringContainsString('failed with exit code', $reporter->errors[0]);
            $this->assertSame($reporter->errors[0], $e->getMessage());
        }

        $quiet = new class (level: Adapter::QUIET) extends FFmpeg {
            public function fail(): void
            {
                $this->process(['false']);
            }
        };

        $quiet->setReporter($reporter);

        try {
            $quiet->fail();
            $this->fail('a failing command should throw');
        } catch (Runtime) {
            $this->assertCount(1, $reporter->errors, 'a quiet backend should say nothing');
        }
    }

    /**
     * Status lines go on the terminal unless told otherwise, because that is
     * what a command line caller wants and it costs everyone else one argument
     * to redirect.
     */
    public function testStatusLinesGoToTheTerminalByDefault(): void
    {
        $adapter = new class () extends Mock {
            public function destination(): Reporter
            {
                return $this->reporter();
            }
        };

        $this->assertInstanceOf(Reporter\Console::class, $adapter->destination());

        // And a facade told to keep quiet replaces it before anything runs.
        new Encoder(adapter: $adapter, probe: $adapter, reporter: new Reporter\Silent());

        $this->assertInstanceOf(Reporter\Silent::class, $adapter->destination());
    }

    /**
     * A worker that does not own stdout hands over somewhere else to report to,
     * and the facade gives it to every backend so half a job cannot end up
     * printing.
     */
    public function testTheReporterGivenToAFacadeIsTheOneBackendsUse(): void
    {
        $reporter = new Recorder();
        $mock = new Mock();

        $package = (new Packager(adapter: $mock, probe: $mock, reporter: $reporter))
            ->open($this->file)
            ->add(new Representation(1280, 720, 2538))
            ->output(new Hls())
            ->pack($this->dir.'/reported');

        $this->assertNotSame([], $package->variants());
        $this->assertSame(['mock: packed '.$this->dir.'/reported'], $reporter->successes);

        $encoded = $this->dir.'/reported.mp4';
        $silent = new Mock();

        (new Encoder(adapter: $silent, probe: $silent, reporter: $reporter))
            ->open($this->file)
            ->encode($encoded);

        $this->assertSame(
            ['mock: packed '.$this->dir.'/reported', 'mock: encoded '.$encoded],
            $reporter->successes,
        );
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
