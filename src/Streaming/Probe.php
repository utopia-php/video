<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use FFMpeg\FFProbe\DataMapping\Format as ProbeFormat;
use FFMpeg\FFProbe\DataMapping\StreamCollection;

/**
 * Snapshot of an ffprobe result for a single media file.
 * Derived once per open() — do not re-probe.
 */
final class Probe
{
    private StreamCollection $streams;

    private bool $hasVideo;

    /** @var list<array{codec: string, language: string}> */
    private array $audioTracks;

    private float $duration;

    public function __construct(StreamCollection $streams, ?ProbeFormat $format = null)
    {
        $this->streams = $streams;
        $this->hasVideo = count($streams->videos()) > 0;
        $this->audioTracks = $this->collectAudioTracks($streams);
        $this->duration = $this->resolveDuration($streams, $format);
    }

    public function getStreams(): StreamCollection
    {
        return $this->streams;
    }

    public function hasVideo(): bool
    {
        return $this->hasVideo;
    }

    public function hasAudio(): bool
    {
        return $this->audioTracks !== [] || count($this->streams->audios()) > 0;
    }

    /**
     * @return list<array{codec: string, language: string}>
     */
    public function getAudioTracks(): array
    {
        return $this->audioTracks;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * @return list<array{codec: string, language: string}>
     */
    private function collectAudioTracks(StreamCollection $streams): array
    {
        $tracks = [];

        foreach ($streams->audios() as $stream) {
            $tags = $stream->get('tags') ?? [];
            $language = is_array($tags) && ! empty($tags['language'])
                ? (string) $tags['language']
                : 'und';

            $tracks[] = [
                'codec' => (string) ($stream->get('codec_name') ?? 'aac'),
                'language' => $language,
            ];
        }

        return $tracks;
    }

    private function resolveDuration(StreamCollection $streams, ?ProbeFormat $format): float
    {
        if ($format !== null) {
            $duration = $format->get('duration');
            if (is_numeric($duration)) {
                return (float) $duration;
            }
        }

        foreach ($streams as $stream) {
            $duration = $stream->get('duration');
            if (is_numeric($duration)) {
                return (float) $duration;
            }
        }

        return 0.0;
    }
}
