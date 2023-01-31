<?php

namespace Utopia\Streaming\Output;

use Utopia\Streaming\Output;

class Hls extends Output
{
    private int $hls_time = 10;

    private bool $hls_allow_cache = true;

    private string $seg_sub_directory = '';

    private string $hls_base_url = '';

    private int $hls_list_size = 0;

    public string $master_playlist;

    private string $hls_segment_type = 'mpegts';

    private string $hls_fmp4_init_filename = 'init.mp4';

    private array $stream_des = [];

    private array $flags = [];

    /**
     * @return string
     */
    public function getSegSubDirectory(): string
    {
        return $this->seg_sub_directory;
    }

    /**
     * @param  string  $seg_sub_directory
     * @return Hls
     */
    public function setSegSubDirectory(string $seg_sub_directory): Hls
    {
        $this->seg_sub_directory = $seg_sub_directory;

        return $this;
    }

    /**
     * @param  string  $hls_time
     * @return HLS
     */
    public function setHlsTime(string $hls_time): HLS
    {
        $this->hls_time = $hls_time;

        return $this;
    }

    /**
     * @return string
     */
    public function getHlsTime(): string
    {
        return $this->hls_time;
    }

    /**
     * @param  bool  $hls_allow_cache
     * @return HLS
     */
    public function setHlsAllowCache(bool $hls_allow_cache): HLS
    {
        $this->hls_allow_cache = $hls_allow_cache;

        return $this;
    }

    /**
     * @return bool
     */
    public function isHlsAllowCache(): bool
    {
        return $this->hls_allow_cache;
    }

    /**
     * @param  string  $hls_base_url
     * @return HLS
     */
    public function setHlsBaseUrl(string $hls_base_url): HLS
    {
        $this->hls_base_url = $hls_base_url;

        return $this;
    }

    /**
     * @return string
     */
    public function getHlsBaseUrl(): string
    {
        return $this->hls_base_url;
    }

    /**
     * @param  int  $hls_list_size
     * @return HLS
     */
    public function setHlsListSize(int $hls_list_size): HLS
    {
        $this->hls_list_size = $hls_list_size;

        return $this;
    }

    /**
     * @return int
     */
    public function getHlsListSize(): int
    {
        return $this->hls_list_size;
    }

    /**
     * @param  string  $master_playlist
     * @param  array  $stream_des
     * @return HLS
     */
    public function setMasterPlaylist(string $master_playlist, array $stream_des = []): HLS
    {
        $this->master_playlist = $master_playlist;
        $this->stream_des = $stream_des;

        return $this;
    }

    /**
     * @return HLS
     */
    public function fragmentedMP4(): HLS
    {
        $this->setHlsSegmentType('fmp4');

        return $this;
    }

    /**
     * @param  string  $hls_segment_type
     * @return HLS
     */
    public function setHlsSegmentType(string $hls_segment_type): HLS
    {
        $this->hls_segment_type = $hls_segment_type;

        return $this;
    }

    /**
     * @return string
     */
    public function getHlsSegmentType(): string
    {
        return $this->hls_segment_type;
    }

    /**
     * @param  string  $hls_fmp4_init_filename
     * @return HLS
     */
    public function setHlsFmp4InitFilename(string $hls_fmp4_init_filename): HLS
    {
        $this->hls_fmp4_init_filename = $hls_fmp4_init_filename;

        return $this;
    }

    /**
     * @return string
     */
    public function getHlsFmp4InitFilename(): string
    {
        return $this->hls_fmp4_init_filename;
    }

    /**
     * @param  array  $flags
     * @return HLS
     */
    public function setFlags(array $flags): HLS
    {
        $this->flags = array_merge($this->flags, $flags);

        return $this;
    }

    /**
     * @return array
     */
    public function getFlags(): array
    {
        return $this->flags;
    }
}
