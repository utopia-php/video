<?php

namespace Utopia\Streaming\Format;

class VP9 extends Format
{
    /**
     * @param  string  $video_codec
     * @param  string|null  $audio_codec
     * @param  bool  $default_init_opts
     */
    public function __construct(string $video_codec = 'libvpx-vp9', string $audio_codec = 'aac', bool $default_init_opts = true)
    {
        $this
            ->setVideoCodec($video_codec)
            ->setAudioCodec($audio_codec);

        /**
         * set the default value of h265 codec options
         * see https://ffmpeg.org/ffmpeg-codecs.html#Options-26 for more information about options
         */
        if ($default_init_opts) {
            //@TODO: add default vp9
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableAudioCodecs()
    {
        return ['libvorbis'];
    }

    /**
     * @return array
     */
    public function getAvailableVideoCodecs(): array
    {
        return ['libvpx', 'libvpx-vp9'];
    }

    /**
     * Returns true if the current format supports B-Frames.
     *
     * @see https://wikipedia.org/wiki/Video_compression_picture_types
     *
     * @return bool
     */
    public function supportBFrames(): bool
    {
        return true;
    }
}
