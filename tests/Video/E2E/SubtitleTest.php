<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Video\Encoder;
use Utopia\Video\Exception\Input;
use Utopia\Video\Process;
use Utopia\Video\Track;

class SubtitleTest extends Base
{
    /**
     * The sample clip with one mov_text track muxed in from a two-cue SubRip file.
     */
    private function subtitled(): string
    {
        $srt = $this->dir.'/cues.srt';
        \file_put_contents(
            $srt,
            "1\n00:00:00,500 --> 00:00:02,000\nFIRST CUE\n\n2\n00:00:03,000 --> 00:00:05,000\nSECOND CUE\n",
        );

        $source = $this->dir.'/with-subs.mp4';
        Process::run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', self::video(), '-i', $srt,
            '-map', '0', '-map', '1',
            '-c', 'copy', '-c:s', 'mov_text',
            '-metadata:s:s:0', 'language=eng',
            $source,
        ], timeout: 60);

        return $source;
    }

    public function testWritesTheStreamAsWebvtt(): void
    {
        $source = $this->subtitled();
        $tracks = (new Encoder())->probe($source)->tracks(Track::SUBTITLE);

        $this->assertCount(1, $tracks);

        $path = (new Encoder())->subtitle($source, $tracks[0]->index, $this->dir.'/nested/eng.vtt');

        $this->assertSame($this->dir.'/nested/eng.vtt', $path);
        $this->assertWritten($path);

        $vtt = (string) \file_get_contents($path);

        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertStringContainsString('FIRST CUE', $vtt);
        // ffmpeg's WebVTT muxer drops the hours field while it is zero.
        $this->assertStringContainsString('00:03.000 --> 00:05.000', $vtt);
    }

    public function testRefusesAStreamThatIsNotASubtitle(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('no subtitle stream 0');

        (new Encoder())->subtitle(self::video(), 0, $this->dir.'/nothing.vtt');
    }

    public function testRefusesAStreamTheSourceDoesNotHave(): void
    {
        $this->expectException(Input::class);
        $this->expectExceptionMessage('no subtitle stream 9');

        (new Encoder())->subtitle($this->subtitled(), 9, $this->dir.'/nothing.vtt');
    }
}
