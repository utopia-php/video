<?php

declare(strict_types=1);

namespace Utopia\Streaming\Format;

use Utopia\Streaming\Format;

class VP9 extends Format
{
    private const MODULUS = 2;

    private const AUDIO_CODECS = ['libvorbis', 'aac'];

    private const VIDEO_CODECS = ['libvpx', 'libvpx-vp9'];

    public function __construct(
        string $videoCodec = 'libvpx-vp9',
        string $audioCodec = 'libvorbis',
        bool $defaultInitOpts = true
    ) {
        $this
            ->setVideoCodec($videoCodec)
            ->setAudioCodec($audioCodec);

        if ($defaultInitOpts) {
            $this->setAdditionalParameters([
                'deadline' => 'good',
                'cpu-used' => 2,
                'row-mt' => 1,
                'tile-columns' => 2,
                'frame-parallel' => 1,
                'g' => 250,
                'keyint_min' => 25,
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
        return false;
    }
}
