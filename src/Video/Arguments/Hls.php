<?php

declare(strict_types=1);

namespace Utopia\Video\Arguments;

use Utopia\Video\Arguments;
use Utopia\Video\Exception\Unsupported;
use Utopia\Video\Output\Hls as HlsOutput;

/**
 * One hls muxer invocation covering every rung.
 *
 * @internal
 */
final class Hls extends Arguments
{
    public function build(): array
    {
        $hls = $this->output;

        if (! $hls instanceof HlsOutput) {
            throw new Unsupported('Expected an HLS output');
        }

        $args = $this->maps();

        foreach ($this->streams() as $arg) {
            $args[] = $arg;
        }

        $args[] = '-f';
        $args[] = 'hls';
        $args[] = '-hls_time';
        $args[] = self::number($hls->duration());
        $args[] = '-hls_list_size';
        $args[] = '0';
        $args[] = '-hls_playlist_type';
        $args[] = 'vod';
        $args[] = '-hls_segment_type';
        $args[] = $hls->container();
        $args[] = '-hls_segment_filename';
        $args[] = $this->path($hls->base().'_%v_%04d.'.$hls->extension());

        if ($hls->fragmented()) {
            $args[] = '-hls_fmp4_init_filename';
            $args[] = $hls->base().'_%v_'.$hls->initFile();
        }

        if ($hls->prefix() !== '') {
            $args[] = '-hls_base_url';
            $args[] = $hls->prefix();
        }

        if ($hls->hlsFlags() !== []) {
            $args[] = '-hls_flags';
            $args[] = \implode('+', $hls->hlsFlags());
        }

        $args[] = '-master_pl_name';
        $args[] = $hls->masterFile();
        $args[] = '-var_stream_map';
        $args[] = $this->map();

        foreach ($hls->extra() as $param) {
            $args[] = $param;
        }

        return $args;
    }

    public function target(): string
    {
        return $this->path($this->output->base().'_%v.m3u8');
    }

    /**
     * Describes each variant, and which audio group the video rungs pull from.
     */
    private function map(): string
    {
        $entries = [];

        foreach ($this->rungs() as $position => $rep) {
            $entry = 'v:'.$position.',name:'.$rep->name;

            if ($this->sound() !== []) {
                $entry .= ',agroup:audio';
            }

            $entries[] = $entry;
        }

        foreach ($this->sound() as $position => $track) {
            $entry = 'a:'.$position.',agroup:audio';

            if ($track['language'] !== '') {
                $entry .= ',language:'.$track['language'];
            }

            $entry .= ',name:audio_'.$position;

            if ($position === 0) {
                $entry .= ',default:yes';
            }

            $entries[] = $entry;
        }

        return \implode(' ', $entries);
    }
}
