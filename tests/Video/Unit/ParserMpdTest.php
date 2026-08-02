<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Parser\Mpd;
use Utopia\Video\Track;

class ParserMpdTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir().'/utopia-mpd-'.\bin2hex(\random_bytes(6));
        \mkdir($dir, 0o755, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir.'/*') ?: [] as $file) {
            \unlink($file);
        }

        @\rmdir($this->dir);
    }

    private function write(string $name, string $body): string
    {
        $path = $this->dir.'/'.$name;
        \file_put_contents($path, $body);

        return $path;
    }

    /**
     * @testdox Reads ISO 8601 durations
     */
    public function testReadsIsoDurations(): void
    {
        $this->assertSame(0.0, Mpd::seconds(''));
        $this->assertSame(64.5, Mpd::seconds('PT1M4.5S'));
        $this->assertSame(3725.0, Mpd::seconds('PT1H2M5.0S'));
        $this->assertSame(6.0, Mpd::seconds('PT6S'));
    }

    public function testExpandsSegmentNamePatterns(): void
    {
        $this->assertSame(
            'chunk-stream0-00007.m4s',
            Mpd::expand('chunk-stream$RepresentationID$-$Number%05d$.m4s', '0', 7),
        );

        $this->assertSame(
            'seg-3.m4s',
            Mpd::expand('seg-$Number$.m4s', '0', 3),
        );

        $this->assertSame(
            'init-stream1.m4s',
            Mpd::expand('init-stream$RepresentationID$.m4s', '1', 0),
        );
    }

    public function testReadsAListedManifest(): void
    {
        $this->write('init-0.m4s', 'init');
        $this->write('chunk-0-1.m4s', 'aaaa');
        $this->write('chunk-0-2.m4s', 'bb');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-on-demand:2011"
                 type="static" mediaPresentationDuration="PT8.0S" maxSegmentDuration="PT6.0S"
                 minBufferTime="PT6.0S">
              <Period>
                <AdaptationSet id="0" contentType="video" maxWidth="1280" maxHeight="720" par="16:9">
                  <Representation id="0" mimeType="video/mp4" codecs="avc1.64001f" bandwidth="2538000"
                                  width="1280" height="720" sar="1:1">
                    <SegmentList timescale="1000000" duration="6000000" startNumber="1">
                      <Initialization sourceURL="init-0.m4s"/>
                      <SegmentURL media="chunk-0-1.m4s"/>
                      <SegmentURL media="chunk-0-2.m4s"/>
                    </SegmentList>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $read = Mpd::read($manifest, $this->dir);

        $this->assertSame('static', $read['metadata']['type']);
        $this->assertSame(8.0, $read['metadata']['duration']);
        $this->assertSame('PT6.0S', $read['metadata']['maxSegmentDuration']);

        $this->assertCount(1, $read['variants']);
        $variant = $read['variants'][0];

        $this->assertSame('0', $variant->id);
        $this->assertSame(Track::VIDEO, $variant->type);
        $this->assertSame('avc1.64001f', $variant->codecs);
        $this->assertSame(2538000, $variant->bandwidth);
        $this->assertSame(1280, $variant->width);
        $this->assertSame('1:1', $variant->sar);
        $this->assertSame(1000000, $variant->timescale);
        $this->assertSame(1, $variant->startNumber);

        $this->assertCount(3, $variant->segments);
        $this->assertTrue($variant->segments[0]->init);
        $this->assertSame(6.0, $variant->segments[1]->duration);
        $this->assertSame(1, $variant->segments[1]->number);
        $this->assertSame(2, $variant->segments[2]->number);
    }

    /**
     * A manifest that only describes a formula still has to come back as a
     * concrete list of segments; expanding it here means callers never have to
     * care which addressing mode was used.
     */
    public function testExpandsATemplatedManifestWithATimeline(): void
    {
        $this->write('init-stream0.m4s', 'init');
        $this->write('chunk-stream0-00001.m4s', 'a');
        $this->write('chunk-stream0-00002.m4s', 'b');
        $this->write('chunk-stream0-00003.m4s', 'c');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT14.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="0" mimeType="video/mp4" bandwidth="800000" width="640" height="360">
                    <SegmentTemplate timescale="1000" duration="6000" startNumber="1"
                                     initialization="init-stream$RepresentationID$.m4s"
                                     media="chunk-stream$RepresentationID$-$Number%05d$.m4s">
                      <SegmentTimeline>
                        <S t="0" d="6000" r="1"/>
                        <S d="2000"/>
                      </SegmentTimeline>
                    </SegmentTemplate>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $read = Mpd::read($manifest, $this->dir);
        $variant = $read['variants'][0];

        $this->assertCount(4, $variant->segments);
        $this->assertTrue($variant->segments[0]->init);
        $this->assertSame('chunk-stream0-00001.m4s', $variant->segments[1]->file);
        $this->assertSame(6.0, $variant->segments[1]->duration);
        $this->assertSame('chunk-stream0-00002.m4s', $variant->segments[2]->file);
        $this->assertSame('chunk-stream0-00003.m4s', $variant->segments[3]->file);
        $this->assertSame(2.0, $variant->segments[3]->duration);
    }

    public function testExpandsATemplatedManifestWithoutATimeline(): void
    {
        $this->write('init-stream0.m4s', 'init');
        $this->write('chunk-stream0-00001.m4s', 'a');
        $this->write('chunk-stream0-00002.m4s', 'b');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT10.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="0" mimeType="video/mp4" bandwidth="800000">
                    <SegmentTemplate timescale="1000" duration="6000" startNumber="1"
                                     initialization="init-stream$RepresentationID$.m4s"
                                     media="chunk-stream$RepresentationID$-$Number%05d$.m4s"/>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $variant = Mpd::read($manifest, $this->dir)['variants'][0];

        $this->assertCount(3, $variant->segments);
        $this->assertSame('chunk-stream0-00002.m4s', $variant->segments[2]->file);
    }

    public function testAudioAdaptationSetIsRecognised(): void
    {
        $this->write('init-1.m4s', 'init');
        $this->write('chunk-1-1.m4s', 'a');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT6.0S">
              <Period>
                <AdaptationSet id="1" contentType="audio" lang="eng">
                  <Representation id="1" mimeType="audio/mp4" codecs="mp4a.40.2" bandwidth="128000"
                                  audioSamplingRate="48000">
                    <SegmentList timescale="1000" duration="6000" startNumber="1">
                      <Initialization sourceURL="init-1.m4s"/>
                      <SegmentURL media="chunk-1-1.m4s"/>
                    </SegmentList>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $variant = Mpd::read($manifest, $this->dir)['variants'][0];

        $this->assertSame(Track::AUDIO, $variant->type);
        $this->assertSame('eng', $variant->language);
        $this->assertSame(48000, $variant->sampleRate);
        $this->assertSame('audio/mp4', $variant->mimeType);
        $this->assertNull($variant->resolution());
    }

    public function testMissingSegmentIsAFailure(): void
    {
        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT6.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="0" bandwidth="1">
                    <SegmentList timescale="1000" duration="6000">
                      <SegmentURL media="gone.m4s"/>
                    </SegmentList>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $this->expectException(Runtime::class);
        $this->expectExceptionMessage('gone.m4s');

        Mpd::read($manifest, $this->dir);
    }

    public function testUnreadableManifestIsAFailure(): void
    {
        $this->expectException(Runtime::class);

        Mpd::read($this->dir.'/nothing.mpd', $this->dir);
    }

    public function testMalformedManifestIsAFailure(): void
    {
        $manifest = $this->write('manifest.mpd', '<MPD><unclosed>');

        $this->expectException(Runtime::class);

        Mpd::read($manifest, $this->dir);
    }
}
