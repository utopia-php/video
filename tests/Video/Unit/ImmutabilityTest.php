<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

/**
 * Config objects are shared — across jobs, containers, and coroutines — so a
 * setter that touched its receiver would let one caller's settings bleed into
 * another's. Every setter has to return a modified copy instead.
 */
class ImmutabilityTest extends TestCase
{
    /**
     * One row per setter: build a receiver, call the setter, and read the
     * value back off both objects. The closures are typed per class, which is
     * why the rows are declared with plain callable.
     *
     * @return array<string, array{0: callable, 1: callable, 2: callable, 3: mixed, 4: mixed}>
     */
    public static function setters(): array
    {
        return [
            'Format::crf' => [
                fn () => new X264(),
                fn (X264 $it) => $it->crf(18),
                fn (X264 $it) => \in_array('-crf', $it->build(), true),
                false,
                true,
            ],
            'Format::bframes' => [
                fn () => new X264(),
                fn (X264 $it) => $it->bframes(3),
                fn (X264 $it) => \in_array('-bf', $it->build(), true),
                false,
                true,
            ],
            'Format::keyframe' => [
                fn () => new X264(),
                fn (X264 $it) => $it->keyframe(2.0),
                fn (X264 $it) => $it->interval(),
                null,
                2.0,
            ],
            'Format::params' => [
                fn () => new X264(),
                fn (X264 $it) => $it->params(['-movflags', '+faststart']),
                fn (X264 $it) => \in_array('-movflags', $it->build(), true),
                false,
                true,
            ],
            'Output::segment' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->segment(2.0),
                fn (Hls $it) => $it->duration(),
                6.0,
                2.0,
            ],
            'Output::manifests' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->manifests(false),
                fn (Hls $it) => $it->keeps(),
                true,
                false,
            ],
            'Output::name' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->name('ladder'),
                fn (Hls $it) => $it->base(),
                'stream',
                'ladder',
            ],
            'Output::params' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->params(['-max_muxing_queue_size', '1024']),
                fn (Hls $it) => $it->extra(),
                [],
                ['-max_muxing_queue_size', '1024'],
            ],
            'Hls::segments' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->segments(Hls::FMP4),
                fn (Hls $it) => $it->container(),
                Hls::MPEGTS,
                Hls::FMP4,
            ],
            'Hls::init' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->init('start.mp4'),
                fn (Hls $it) => $it->initFile(),
                'init.mp4',
                'start.mp4',
            ],
            'Hls::master' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->master('index.m3u8'),
                fn (Hls $it) => $it->masterFile(),
                'master.m3u8',
                'index.m3u8',
            ],
            'Hls::url' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->url('https://cdn.example/'),
                fn (Hls $it) => $it->prefix(),
                '',
                'https://cdn.example/',
            ],
            'Hls::flags' => [
                fn () => new Hls(),
                fn (Hls $it) => $it->flags(['temp_file']),
                fn (Hls $it) => $it->hlsFlags(),
                ['independent_segments'],
                ['temp_file'],
            ],
            'Dash::template' => [
                fn () => new Dash(),
                fn (Dash $it) => $it->template(false),
                fn (Dash $it) => $it->templated(),
                true,
                false,
            ],
            'Dash::timeline' => [
                fn () => new Dash(),
                fn (Dash $it) => $it->timeline(false),
                fn (Dash $it) => $it->timelined(),
                true,
                false,
            ],
            'Dash::manifest' => [
                fn () => new Dash(),
                fn (Dash $it) => $it->manifest('video.mpd'),
                fn (Dash $it) => $it->manifestFile(),
                'manifest.mpd',
                'video.mpd',
            ],
            'Dash::init' => [
                fn () => new Dash(),
                fn (Dash $it) => $it->init('i-$RepresentationID$.$ext$'),
                fn (Dash $it) => $it->initPattern(),
                'stream_init_$RepresentationID$.$ext$',
                'i-$RepresentationID$.$ext$',
            ],
            'Dash::media' => [
                fn () => new Dash(),
                fn (Dash $it) => $it->media('m-$Number$.$ext$'),
                fn (Dash $it) => $it->mediaPattern(),
                'stream_chunk_$RepresentationID$_$Number%05d$.$ext$',
                'm-$Number$.$ext$',
            ],
            'Dash::sets' => [
                fn () => new Dash(),
                fn (Dash $it) => $it->sets('id=0,streams=v'),
                fn (Dash $it) => $it->adaptations(1, 1),
                'id=0,streams=v id=1,streams=a',
                'id=0,streams=v',
            ],
            'Cmaf::master' => [
                fn () => new Cmaf(),
                fn (Cmaf $it) => $it->master('index.m3u8'),
                fn (Cmaf $it) => $it->masterFile(),
                'master.m3u8',
                'index.m3u8',
            ],
            'Thumb::time' => [
                fn () => new Thumb(),
                fn (Thumb $it) => $it->time(12.5),
                fn (Thumb $it) => $it->at(),
                null,
                12.5,
            ],
            'Thumb::width' => [
                fn () => new Thumb(),
                fn (Thumb $it) => $it->width(640),
                fn (Thumb $it) => $it->size(),
                320,
                640,
            ],
            'Thumb::quality' => [
                fn () => new Thumb(),
                fn (Thumb $it) => $it->quality(4),
                fn (Thumb $it) => $it->scale(),
                2,
                4,
            ],
            'Tile::interval' => [
                fn () => new Tile(),
                fn (Tile $it) => $it->interval(5.0),
                fn (Tile $it) => $it->every(30.0),
                2.0,
                5.0,
            ],
            'Tile::width' => [
                fn () => new Tile(),
                fn (Tile $it) => $it->width(200),
                fn (Tile $it) => $it->size(),
                160,
                200,
            ],
            'Tile::grid' => [
                fn () => new Tile(),
                fn (Tile $it) => $it->grid(4, 3),
                fn (Tile $it) => $it->cells(),
                25,
                12,
            ],
            'Tile::quality' => [
                fn () => new Tile(),
                fn (Tile $it) => $it->quality(5),
                fn (Tile $it) => $it->scale(),
                3,
                5,
            ],
            'Tile::name' => [
                fn () => new Tile(),
                fn (Tile $it) => $it->name('thumbs'),
                fn (Tile $it) => $it->base(),
                'sprite',
                'thumbs',
            ],
            'Tile::vtt' => [
                fn () => new Tile(),
                fn (Tile $it) => $it->vtt(false),
                fn (Tile $it) => $it->writes(),
                true,
                false,
            ],
        ];
    }

    /**
     * @dataProvider setters
     */
    public function testASetterReturnsACopyAndLeavesItsReceiverAlone(
        callable $make,
        callable $set,
        callable $read,
        mixed $default,
        mixed $changed,
    ): void {
        $original = $make();
        $modified = $set($original);

        $this->assertNotSame($original, $modified, 'a setter has to return a new instance');
        $this->assertSame($default, $read($original), 'the receiver must keep its value');
        $this->assertSame($changed, $read($modified), 'the copy must carry the new value');
    }

    /**
     * A chain accumulates across copies: each call starts from the last copy,
     * so the end of the chain carries everything set along the way.
     */
    public function testAChainAccumulatesAcrossCopies(): void
    {
        $format = (new X264())->crf(22)->bframes(3);
        $args = $format->build();

        $this->assertContains('-crf', $args);
        $this->assertContains('-bf', $args);
    }

    /**
     * The sharing scenario itself: two jobs configure one shared preset and
     * neither sees the other's settings.
     */
    public function testTwoHoldersOfOneInstanceCannotAffectEachOther(): void
    {
        $shared = new X264();

        $first = $shared->crf(18);
        $second = $shared->crf(30);

        $this->assertContains('18', $first->build());
        $this->assertContains('30', $second->build());
        $this->assertNotContains('-crf', $shared->build());
    }
}
