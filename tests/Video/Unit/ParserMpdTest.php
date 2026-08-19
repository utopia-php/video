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

        // The date half is legal too, and means the same thing.
        $this->assertSame(64.5, Mpd::seconds('P0Y0M0DT0H1M4.50S'));
        $this->assertSame(93784.0, Mpd::seconds('P1DT2H3M4S'));
        $this->assertSame(604800.0, Mpd::seconds('P1W'));

        // Years and months have no fixed length, so they are refused, not guessed.
        $this->assertSame(0.0, Mpd::seconds('P1Y'));
        $this->assertSame(0.0, Mpd::seconds('P1M'));

        $this->assertSame(0.0, Mpd::seconds('P'));
        $this->assertSame(0.0, Mpd::seconds('nonsense'));
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

    /**
     * A manifest is free to address its segments by bitrate instead of by id,
     * and a reader that dropped the identifier would look for the wrong file.
     */
    public function testExpandsTheBandwidthIdentifier(): void
    {
        $this->assertSame(
            'chunk-2538000-4.m4s',
            Mpd::expand('chunk-$Bandwidth$-$Number$.m4s', '0', 4, 2538000),
        );

        $this->assertSame(
            'seg-0002538000.m4s',
            Mpd::expand('seg-$Bandwidth%010d$.m4s', '0', 1, 2538000),
        );
    }

    /**
     * @testdox A literal dollar sign survives expansion
     */
    public function testLiteralDollarSignSurvives(): void
    {
        $this->assertSame('odd$name-3.m4s', Mpd::expand('odd$$name-$Number$.m4s', '0', 3, 800));
    }

    public function testReadsATemplatedManifestAddressedByBandwidth(): void
    {
        $this->write('init-800000.m4s', 'init');
        $this->write('chunk-800000-00001.m4s', 'a');
        $this->write('chunk-800000-00002.m4s', 'b');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT10.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="0" mimeType="video/mp4" bandwidth="800000">
                    <SegmentTemplate timescale="1000" duration="6000" startNumber="1"
                                     initialization="init-$Bandwidth$.m4s"
                                     media="chunk-$Bandwidth$-$Number%05d$.m4s"/>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $variant = Mpd::read($manifest, $this->dir)['variants'][0];

        $this->assertSame(800000, $variant->bandwidth);
        $this->assertCount(3, $variant->segments);
        $this->assertSame('init-800000.m4s', $variant->segments[0]->file);
        $this->assertSame('chunk-800000-00001.m4s', $variant->segments[1]->file);
        $this->assertSame('chunk-800000-00002.m4s', $variant->segments[2]->file);
    }

    /**
     * maxSegmentDuration is an ISO 8601 duration, and it is declared once on the
     * MPD root. Read off an AdaptationSet, or cast with (float), it is silently 0.
     */
    public function testAVariantCarriesTheSegmentLengthTheManifestDeclares(): void
    {
        $this->write('init-0.m4s', 'init');
        $this->write('chunk-0-1.m4s', 'aaaa');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD type="static" mediaPresentationDuration="PT8.0S" maxSegmentDuration="PT6.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="0" mimeType="video/mp4" bandwidth="2538000" width="1280" height="720">
                    <SegmentList timescale="1000000" duration="6000000" startNumber="1">
                      <Initialization sourceURL="init-0.m4s"/>
                      <SegmentURL media="chunk-0-1.m4s"/>
                    </SegmentList>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $read = Mpd::read($manifest, $this->dir);

        $this->assertSame(6.0, $read['variants'][0]->target);
    }

    /**
     * With no maximum declared, the longest segment is what a target means.
     */
    public function testAVariantFallsBackToItsLongestSegment(): void
    {
        $this->write('chunk-0-1.m4s', 'aaaa');

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD type="static" mediaPresentationDuration="PT4.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="0" mimeType="video/mp4" bandwidth="1000" width="640" height="360">
                    <SegmentList timescale="1000000" duration="4000000" startNumber="1">
                      <SegmentURL media="chunk-0-1.m4s"/>
                    </SegmentList>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $read = Mpd::read($manifest, $this->dir);

        $this->assertSame(4.0, $read['variants'][0]->target);
    }

    /**
     * A descendant search run from an AdaptationSet walks into its Representations,
     * so a rung that declares its own addressing must not be handed a sibling's.
     */
    public function testARungKeepsItsOwnSegmentsWhenASiblingAddressesThemDifferently(): void
    {
        foreach (['a_init.m4s', 'a_1.m4s', 'b_init.m4s', 'b_1.m4s'] as $file) {
            $this->write($file, 'x');
        }

        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD type="static" mediaPresentationDuration="PT2.0S" maxSegmentDuration="PT2.0S">
              <Period>
                <AdaptationSet contentType="video" mimeType="video/mp4">
                  <Representation id="a" bandwidth="1000" width="640" height="360">
                    <SegmentTemplate timescale="1" duration="2" startNumber="1"
                                     initialization="a_init.m4s" media="a_$Number$.m4s"/>
                  </Representation>
                  <Representation id="b" bandwidth="2000" width="1280" height="720">
                    <SegmentList timescale="1" duration="2">
                      <Initialization sourceURL="b_init.m4s"/>
                      <SegmentURL media="b_1.m4s"/>
                    </SegmentList>
                  </Representation>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $read = Mpd::read($manifest, $this->dir);

        $this->assertCount(2, $read['variants']);
        $this->assertSame(
            ['a_init.m4s', 'a_1.m4s'],
            \array_map(static fn ($segment) => $segment->file, $read['variants'][0]->segments),
        );
        $this->assertSame(
            ['b_init.m4s', 'b_1.m4s'],
            \array_map(static fn ($segment) => $segment->file, $read['variants'][1]->segments),
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

    public function testARepresentationWithoutMediaSegmentsIsAFailure(): void
    {
        $manifest = $this->write('manifest.mpd', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT6.0S">
              <Period>
                <AdaptationSet id="0" contentType="video">
                  <Representation id="empty" bandwidth="800000"/>
                </AdaptationSet>
              </Period>
            </MPD>
            XML);

        $this->expectException(Runtime::class);
        $this->expectExceptionMessage('Representation "empty" contains no media segments');

        Mpd::read($manifest, $this->dir);
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
