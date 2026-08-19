<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Exception\Input;
use Utopia\Video\Representation;

class RepresentationTest extends TestCase
{
    /**
     * @testdox A representation names itself after its height
     */
    public function testDescribesItself(): void
    {
        $rep = new Representation(width: 1280, height: 720, video: 2538, audio: 128);

        $this->assertSame(1280, $rep->width);
        $this->assertSame(720, $rep->height);
        $this->assertSame(2538, $rep->video);
        $this->assertSame(128, $rep->audio);
        $this->assertSame('1280x720', $rep->resolution());
        $this->assertTrue($rep->scaled());
    }

    public function testNamesItselfAfterItsHeight(): void
    {
        $this->assertSame('720p', (new Representation(1280, 720, 2538))->name);
        $this->assertSame('low', (new Representation(1280, 720, 2538, name: 'low'))->name);
    }

    public function testCapsBitrateByDefault(): void
    {
        $rep = new Representation(1280, 720, 2500);

        $this->assertSame(2500, $rep->maxrate);
        $this->assertSame(5000, $rep->bufsize);
    }

    public function testAcceptsAnExplicitCap(): void
    {
        $rep = new Representation(1280, 720, 2500, maxrate: 3000, bufsize: 9000);

        $this->assertSame(3000, $rep->maxrate);
        $this->assertSame(9000, $rep->bufsize);
    }

    public function testAudioOnlyRungCarriesNoFrame(): void
    {
        $rep = new Representation(width: 0, height: 0, video: 1, audio: 96);

        $this->assertFalse($rep->scaled());
        $this->assertSame('audio', $rep->name);
    }

    public function testRejectsOddDimensions(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('must be even');

        new Representation(1281, 720, 2500);
    }

    public function testRejectsNegativeDimensions(): void
    {
        $this->expectException(Input::class);

        new Representation(-2, 720, 2500);
    }

    public function testRejectsUnusableBitrates(): void
    {
        $this->expectException(Input::class);

        new Representation(1280, 720, 0);
    }

    public function testRejectsUnusableAudioBitrate(): void
    {
        $this->expectException(Input::class);

        new Representation(1280, 720, 2500, audio: 0);
    }

    /**
     * The name becomes a filename while a ladder is being staged, so a name that
     * climbs out of the working directory would write wherever it pointed.
     *
     * @testdox A name that leaves the working directory is rejected
     */
    public function testRejectsANameThatEscapesTheWorkingDirectory(): void
    {
        foreach (['../../outside', 'sub/dir', '/absolute', '..'] as $name) {
            try {
                new Representation(1280, 720, 2500, name: $name);
                $this->fail('expected "'.$name.'" to be rejected');
            } catch (Input $exception) {
                $this->assertStringContainsString('not usable as a name', $exception->getMessage());
            }
        }
    }

    /**
     * The muxer is told which rendition is which in a comma separated list of
     * space separated entries, so a name containing either would be read as the
     * end of the entry rather than part of the name.
     */
    public function testRejectsANameThatWouldBreakTheStreamMap(): void
    {
        foreach (['720p,name:evil', 'two words', ''] as $name) {
            try {
                new Representation(1280, 720, 2500, name: $name);
                $this->fail('expected "'.$name.'" to be rejected');
            } catch (Input $exception) {
                $this->assertStringContainsString('not usable as a name', $exception->getMessage());
            }
        }
    }

    public function testAcceptsTheNamesLaddersActuallyUse(): void
    {
        foreach (['720p', 'low', 'audio_eng', 'rung-1', 'HD'] as $name) {
            $this->assertSame($name, (new Representation(1280, 720, 2500, name: $name))->name);
        }
    }
}
