<?php

declare(strict_types=1);

namespace Utopia\Streaming\Arguments;

use Utopia\Streaming\Arguments;
use Utopia\Streaming\Exception\InvalidArgumentException;
use Utopia\Streaming\File;
use Utopia\Streaming\Format as StreamingFormat;
use Utopia\Streaming\Output;
use Utopia\Streaming\Output\Hls as HlsOutput;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Representations;
use Utopia\Streaming\Utils;

class Hls extends Arguments
{
    private HlsOutput $hls;

    private string $segmentSubDir = '';

    private string $segmentFilename = '';

    private string $baseUrl = '';

    /** Computed once — does not depend on the representation. */
    private string $streamMap = '';

    /** @var list<string> */
    private array $maps = [];

    public function __construct(
        string $path,
        StreamingFormat $format,
        Output $output,
        Representations $reps
    ) {
        if (! $output instanceof HlsOutput) {
            throw new InvalidArgumentException('Arguments\\Hls requires Output\\Hls');
        }

        parent::__construct($path, $format, $output, $reps);

        $this->hls = $output;
        $this->preparePaths();
        $this->streamMap = $this->buildStreamMap();
        $this->maps = $this->buildMaps();
    }

    public function build(): array
    {
        if ($this->reps->count() === 0) {
            throw new InvalidArgumentException('At least one representation is required');
        }

        $chunks = [];
        $last = $this->reps->last();

        foreach ($this->reps as $rep) {
            $chunks[] = $this->formatOptions();
            $chunks[] = Utils::arrayToFFmpegOpt($this->representationOptions($rep));
            $chunks[] = $this->maps;
            $chunks[] = Utils::arrayToFFmpegOpt($this->hls->getAdditionalParams());
            $chunks[] = ['-strict', $this->hls->getStrict()];

            // Intermediate variant playlists are positional outputs;
            // the last rung becomes the command's output argument.
            if ($rep !== $last) {
                $chunks[] = [$this->variantPlaylistPath($rep)];
            }
        }

        return array_merge(...$chunks);
    }

    public function getOutputPath(): string
    {
        $last = $this->reps->last();
        if ($last === null) {
            throw new InvalidArgumentException('At least one representation is required');
        }

        return $this->variantPlaylistPath($last);
    }

    private function preparePaths(): void
    {
        if ($this->hls->getSegmentSubDirectory() !== '') {
            File::makeDir($this->dirname.'/'.$this->hls->getSegmentSubDirectory());
        }

        $this->segmentSubDir = Utils::appendSlash($this->hls->getSegmentSubDirectory());
        $this->segmentFilename = $this->dirname.'/'.$this->segmentSubDir.$this->filename;
        $this->baseUrl = Utils::appendSlash($this->hls->getBaseUrl()).$this->segmentSubDir;
    }

    /**
     * @return array<string, mixed>
     */
    private function representationOptions(Representation $rep): array
    {
        $opts = [
            'hls_list_size' => $this->hls->getPlaylistSize(),
            'hls_time' => $this->hls->getSegmentDuration(),
            'hls_allow_cache' => (int) $this->hls->allowsCache(),
            'hls_segment_type' => $this->hls->getSegmentType(),
            'hls_fmp4_init_filename' => $this->initFilename($rep),
            'hls_segment_filename' => $this->segmentFilename($rep),
            'master_pl_name' => $this->hls->getMasterPlaylistName(),
            'f' => 'hls',
            'var_stream_map' => $this->streamMap,
        ];

        if ($this->hls->hasVideo()) {
            $opts['s:v:0'] = $rep->getResolution();
            $opts['b:v:0'] = $rep->getVideoKiloBitrate().'k';
        }

        foreach ($this->hls->getAudioTracks() as $i => $track) {
            $bitrate = $rep->getAudioKiloBitrate();
            if ($bitrate > 0) {
                $opts['b:a:'.$i] = $bitrate.'k';
            }
        }

        if ($this->baseUrl !== '') {
            $opts['hls_base_url'] = $this->baseUrl;
        }

        if ($this->hls->getFlags() !== []) {
            $opts['hls_flags'] = implode('+', $this->hls->getFlags());
        }

        return $opts;
    }

    private function initFilename(Representation $rep): string
    {
        $height = $this->hls->hasVideo() ? $rep->getHeight().'p_' : '';

        return $this->segmentSubDir.$this->filename.'_%v_'.$height.$this->hls->getInitFilename();
    }

    private function segmentFilename(Representation $rep): string
    {
        $ext = $this->hls->getSegmentType() === 'fmp4' ? 'm4s' : 'ts';
        $height = $this->hls->hasVideo() ? $rep->getHeight().'p_' : '';

        return $this->segmentFilename.'_%v_'.$height.'%04d.'.$ext;
    }

    private function variantPlaylistPath(Representation $rep): string
    {
        if ($this->hls->hasVideo()) {
            return $this->basePath().'_%v_'.$rep->getHeight().'p.m3u8';
        }

        return $this->basePath().'_%v.m3u8';
    }

    private function buildStreamMap(): string
    {
        $parts = [];

        foreach ($this->hls->getAudioTracks() as $i => $track) {
            $part = 'a:'.$i.',agroup:audio,language:'.$track['language'];
            if ($i === 0) {
                $part .= ',default:yes';
            }
            $parts[] = $part;
        }

        if ($this->hls->hasVideo()) {
            $parts[] = $this->hls->getAudioTracks() !== []
                ? 'v:0,agroup:audio'
                : 'v:0';
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function buildMaps(): array
    {
        $maps = [];

        if ($this->hls->hasVideo()) {
            $maps[] = '-map';
            $maps[] = '0:v:0';
        }

        foreach ($this->hls->getAudioTracks() as $i => $track) {
            $maps[] = '-map';
            $maps[] = '0:a:'.$i;
        }

        // No named tracks — still map all audio if present so encode doesn't drop it.
        if ($this->hls->getAudioTracks() === [] && ! $this->hls->hasVideo()) {
            $maps[] = '-map';
            $maps[] = '0:a:0';
        }

        return $maps;
    }
}
