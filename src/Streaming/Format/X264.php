<?php

declare(strict_types=1);

namespace Utopia\Streaming\Format;

use Utopia\Streaming\Format;

class X264 extends Format
{
    private const MODULUS = 2;

    private const AUDIO_CODECS = ['aac', 'libvo_aacenc', 'libfaac', 'libmp3lame', 'libfdk_aac'];

    private const VIDEO_CODECS = ['libx264', 'h264', 'h264_afm', 'h264_nvenc'];

    public function __construct(
        string $videoCodec = 'libx264',
        string $audioCodec = 'aac',
        bool $defaultInitOpts = true
    ) {
        $this
            ->setVideoCodec($videoCodec)
            ->setAudioCodec($audioCodec);

        if ($defaultInitOpts) {
            $this->setAdditionalParameters([
                'bf' => 1,
                'keyint_min' => 25,
                'g' => 250,
                'sc_threshold' => 40,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function getAvailableAudioCodecs(): array
    {
        return self::AUDIO_CODECS;
    }

    /**
     * @return list<string>
     */
    public function getAvailableVideoCodecs(): array
    {
        return self::VIDEO_CODECS;
    }

    public function getModulus(): int
    {
        return self::MODULUS;
    }

    public function supportBFrames(): bool
    {
        return true;
    }
}
