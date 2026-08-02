<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Video\Info;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Track;

class ProbeFFprobeTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function read(array $payload): Info
    {
        $probe = new class () extends FFprobe {
            /**
             * @param  array<string, mixed>  $data
             */
            public function parse(array $data): Info
            {
                return $this->info($data);
            }
        };

        return $probe->parse($payload);
    }

    public function testReadsAVideoWithSound(): void
    {
        $info = $this->read([
            'format' => [
                'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                'duration' => '64.5',
                'tags' => ['title' => 'A Film', 'creation_time' => '2026-01-02T03:04:05.000000Z'],
            ],
            'streams' => [
                [
                    'index' => 0,
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'codec_long_name' => 'H.264 / AVC',
                    'profile' => 'High',
                    'width' => 1920,
                    'height' => 1080,
                    'display_aspect_ratio' => '16:9',
                    'avg_frame_rate' => '25/1',
                    'r_frame_rate' => '25/1',
                    'bit_rate' => '4500000',
                    'disposition' => ['default' => 1],
                    'tags' => ['language' => 'und'],
                ],
                [
                    'index' => 1,
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'codec_long_name' => 'AAC',
                    'bit_rate' => '128000',
                    'sample_rate' => '48000',
                    'disposition' => ['default' => 1],
                    'tags' => ['language' => 'eng'],
                ],
            ],
        ]);

        $this->assertSame(64.5, $info->duration);
        $this->assertSame(64500, $info->milliseconds());
        $this->assertTrue($info->hasVideo);
        $this->assertTrue($info->hasAudio);
        $this->assertSame(1920, $info->width);
        $this->assertSame(1080, $info->height);
        $this->assertSame('16:9', $info->aspect);
        $this->assertSame(25.0, $info->fps);
        $this->assertSame('Constant', $info->fpsMode);
        $this->assertSame('h264', $info->videoCodec);
        $this->assertSame('High', $info->videoProfile);
        $this->assertSame(4500000, $info->videoBitrate);
        $this->assertSame('aac', $info->audioCodec);
        $this->assertSame(128000, $info->audioBitrate);
        $this->assertSame(48000, $info->sampleRate);
        $this->assertSame([['codec' => 'aac', 'language' => 'eng']], $info->audioTracks);
        $this->assertSame('A Film', $info->tags['title']);
        $this->assertCount(2, $info->tracks);
    }

    public function testDetectsVariableFrameRate(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [[
                'codec_type' => 'video',
                'avg_frame_rate' => '24000/1001',
                'r_frame_rate' => '30000/1001',
            ]],
        ]);

        $this->assertSame('Variable', $info->fpsMode);
        $this->assertSame(23.976, $info->fps);
    }

    public function testDerivesAspectRatioWhenTheContainerOmitsIt(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080]],
        ]);

        $this->assertNull($info->aspect);
        $this->assertSame('16:9', $info->ratio());
    }

    public function testReadsEveryTrackIncludingSubtitles(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
                [
                    'index' => 1,
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'tags' => ['language' => 'eng'],
                ],
                [
                    'index' => 2,
                    'codec_type' => 'subtitle',
                    'codec_name' => 'mov_text',
                    'tags' => ['language' => 'fra', 'title' => 'French'],
                    'disposition' => ['default' => 0, 'forced' => 1],
                ],
            ],
        ]);

        $subtitles = $info->tracks(Track::SUBTITLE);

        $this->assertCount(1, $subtitles);
        $this->assertSame('fra', $subtitles[0]->language);
        $this->assertSame('French', $subtitles[0]->title);
        $this->assertTrue($subtitles[0]->forced);
        $this->assertFalse($subtitles[0]->default);
        $this->assertCount(1, $info->tracks(Track::VIDEO));
    }

    public function testReadsChapters(): void
    {
        $info = $this->read([
            'format' => ['duration' => '120'],
            'streams' => [['codec_type' => 'video']],
            'chapters' => [
                ['start_time' => '0.000000', 'end_time' => '60.000000', 'tags' => ['title' => 'Intro']],
                ['start_time' => '60.000000', 'end_time' => '120.000000', 'tags' => ['title' => 'Body']],
            ],
        ]);

        $this->assertCount(2, $info->chapters);
        $this->assertSame('Intro', $info->chapters[0]->title);
        $this->assertSame(60.0, $info->chapters[0]->end);
        $this->assertSame(60.0, $info->chapters[1]->start);
    }

    public function testReadsRotation(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [[
                'codec_type' => 'video',
                'side_data_list' => [['rotation' => -90]],
            ]],
        ]);

        $this->assertSame(-90, $info->rotation);
    }

    public function testReadsRotationFromATag(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [['codec_type' => 'video', 'tags' => ['rotate' => '90']]],
        ]);

        $this->assertSame(90, $info->rotation);
    }

    /**
     * Cover art is stored as a one frame video stream, and treating it as the
     * main picture would make an audio file look like a video.
     */
    public function testCoverArtIsNotMistakenForVideo(): void
    {
        $info = $this->read([
            'format' => ['duration' => '180', 'format_name' => 'mp3'],
            'streams' => [
                ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'mp3', 'sample_rate' => '44100'],
                [
                    'index' => 1,
                    'codec_type' => 'video',
                    'codec_name' => 'mjpeg',
                    'disposition' => ['attached_pic' => 1],
                ],
            ],
        ]);

        $this->assertFalse($info->hasVideo);
        $this->assertTrue($info->hasAudio);
        $this->assertNull($info->width);
    }

    public function testFallsBackToStreamDurationWhenTheContainerHasNone(): void
    {
        $info = $this->read([
            'format' => [],
            'streams' => [['codec_type' => 'video', 'duration' => '12.5']],
        ]);

        $this->assertSame(12.5, $info->duration);
    }

    public function testSilentSourceReportsNoAudio(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [['codec_type' => 'video', 'width' => 640, 'height' => 480]],
        ]);

        $this->assertFalse($info->hasAudio);
        $this->assertNull($info->audioCodec);
        $this->assertSame([], $info->audioTracks);
    }

    public function testUnknownValuesBecomeNull(): void
    {
        $info = $this->read([
            'format' => ['duration' => '10'],
            'streams' => [[
                'codec_type' => 'video',
                'bit_rate' => 'N/A',
                'profile' => '',
            ]],
        ]);

        $this->assertNull($info->videoBitrate);
        $this->assertNull($info->videoProfile);
    }

    public function testKeepsTheOriginalPayload(): void
    {
        $payload = ['format' => ['duration' => '10'], 'streams' => []];

        $this->assertSame($payload, $this->read($payload)->raw);
    }

    public function testRejectsAMissingFile(): void
    {
        $probe = new FFprobe();

        $this->assertFalse($probe->valid('/nowhere/nothing.mp4'));
    }

    /**
     * @testdox The probe carries its name
     */
    public function testIsNamed(): void
    {
        $this->assertSame('ffprobe', (new FFprobe())->getName());
    }
}
