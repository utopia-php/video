<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Utopia\Video\Adapter;
use Utopia\Video\Chapter;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Info;
use Utopia\Video\Track;

/**
 * Reads media details with ffprobe's JSON output.
 */
class FFprobe extends Adapter implements Probe
{
    use Reads;

    protected const NAME = 'ffprobe';

    protected const BINARY = 'ffprobe';

    /** Reading metadata is cheap, so it should never hang for long. */
    protected const TIMEOUT = 30;

    /**
     * This adapter is the probe, so it must not delegate to another one.
     */
    protected function prober(): Probe
    {
        return $this;
    }

    public function read(string $path): Info
    {
        // The payload comes back on stdout and the commentary on stderr, so a
        // raised level cannot corrupt the JSON.
        $payload = $this->capture([
            $this->binary,
            '-v', $this->level,
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            '-show_chapters',
            $this->source($path),
        ]);

        $data = \json_decode($payload, true);

        if (! \is_array($data)) {
            throw new Runtime('ffprobe returned output that could not be read');
        }

        return $this->info($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function info(array $data): Info
    {
        /** @var array<string, mixed> $format */
        $format = \is_array($data['format'] ?? null) ? $data['format'] : [];
        /** @var list<array<string, mixed>> $streams */
        $streams = \is_array($data['streams'] ?? null) ? \array_values($data['streams']) : [];

        $video = null;
        $audio = null;
        $cover = null;
        $tracks = [];
        $audioTracks = [];

        foreach ($streams as $stream) {
            $type = \is_string($stream['codec_type'] ?? null) ? $stream['codec_type'] : Track::DATA;

            if ($type === Track::VIDEO && $this->artwork($stream)) {
                $cover ??= $this->integer($stream, 'index') ?? 0;
            }

            if ($type === Track::VIDEO && $video === null && ! $this->artwork($stream)) {
                $video = $stream;
            }

            if ($type === Track::AUDIO) {
                if ($audio === null) {
                    $audio = $stream;
                }

                $audioTracks[] = [
                    'codec' => $this->string($stream, 'codec_name') ?? '',
                    'language' => $this->tag($stream, 'language') ?? 'und',
                ];
            }

            $tracks[] = $this->track($stream, $type);
        }

        $duration = (float) ($this->string($format, 'duration') ?? 0);

        if ($duration <= 0) {
            foreach ($streams as $stream) {
                $duration = \max($duration, (float) ($this->string($stream, 'duration') ?? 0));
            }
        }

        return new Info(
            duration: $duration,
            format: $this->string($format, 'format_name') ?? '',
            hasVideo: $video !== null,
            hasAudio: $audio !== null,
            width: $video !== null ? ($this->integer($video, 'width') ?? 0) : null,
            height: $video !== null ? ($this->integer($video, 'height') ?? 0) : null,
            aspect: $video !== null ? $this->string($video, 'display_aspect_ratio') : null,
            fps: $video !== null ? $this->rate($video) : null,
            fpsMode: $video !== null ? $this->mode($video) : null,
            videoCodec: $video !== null ? $this->string($video, 'codec_name') : null,
            videoFormat: $video !== null ? $this->string($video, 'codec_long_name') : null,
            videoProfile: $video !== null ? $this->string($video, 'profile') : null,
            videoBitrate: $video !== null ? $this->integer($video, 'bit_rate') : null,
            audioCodec: $audio !== null ? $this->string($audio, 'codec_name') : null,
            audioFormat: $audio !== null ? $this->string($audio, 'codec_long_name') : null,
            audioBitrate: $audio !== null ? $this->integer($audio, 'bit_rate') : null,
            sampleRate: $audio !== null ? $this->integer($audio, 'sample_rate') : null,
            audioTracks: $audioTracks,
            tags: $this->tags($format),
            tracks: $tracks,
            chapters: $this->chapters($data),
            rotation: $video !== null ? $this->rotation($video) : null,
            cover: $cover,
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $stream
     */
    private function track(array $stream, string $type): Track
    {
        /** @var array<string, mixed> $disposition */
        $disposition = \is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];

        return new Track(
            index: $this->integer($stream, 'index') ?? 0,
            type: $type,
            codec: $this->string($stream, 'codec_name'),
            language: $this->tag($stream, 'language'),
            title: $this->tag($stream, 'title'),
            default: $this->integer($disposition, 'default') === 1,
            forced: $this->integer($disposition, 'forced') === 1,
            tags: $this->tags($stream),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<Chapter>
     */
    private function chapters(array $data): array
    {
        if (! \is_array($data['chapters'] ?? null)) {
            return [];
        }

        $chapters = [];

        foreach ($data['chapters'] as $chapter) {
            if (! \is_array($chapter)) {
                continue;
            }

            $chapters[] = new Chapter(
                start: (float) ($chapter['start_time'] ?? 0),
                end: (float) ($chapter['end_time'] ?? 0),
                title: $this->tag($chapter, 'title'),
            );
        }

        return $chapters;
    }

    /**
     * A cover image is stored as a single frame video stream.
     *
     * @param  array<string, mixed>  $stream
     */
    private function artwork(array $stream): bool
    {
        return \is_array($stream['disposition'] ?? null)
            && (int) ($stream['disposition']['attached_pic'] ?? 0) === 1;
    }

    /**
     * @param  array<string, mixed>  $stream
     */
    private function rate(array $stream): ?float
    {
        $value = $this->string($stream, 'avg_frame_rate') ?? $this->string($stream, 'r_frame_rate');

        if ($value === null || ! \str_contains($value, '/')) {
            return $value === null ? null : (float) $value;
        }

        [$numerator, $denominator] = \explode('/', $value, 2);

        if ((float) $denominator === 0.0) {
            return null;
        }

        return \round((float) $numerator / (float) $denominator, 3);
    }

    /**
     * @param  array<string, mixed>  $stream
     */
    private function mode(array $stream): ?string
    {
        $average = $this->string($stream, 'avg_frame_rate');
        $real = $this->string($stream, 'r_frame_rate');

        if ($average === null || $real === null) {
            return null;
        }

        return $average === $real ? 'Constant' : 'Variable';
    }

    /**
     * @param  array<string, mixed>  $stream
     */
    private function rotation(array $stream): ?int
    {
        if (\is_array($stream['side_data_list'] ?? null)) {
            foreach ($stream['side_data_list'] as $side) {
                if (\is_array($side) && isset($side['rotation'])) {
                    return (int) $side['rotation'];
                }
            }
        }

        $tag = $this->tag($stream, 'rotate');

        return $tag === null ? null : (int) $tag;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    private function tags(array $source): array
    {
        if (! \is_array($source['tags'] ?? null)) {
            return [];
        }

        $tags = [];

        foreach ($source['tags'] as $key => $value) {
            if (\is_string($key) && (\is_string($value) || \is_numeric($value))) {
                $tags[\strtolower($key)] = (string) $value;
            }
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function tag(array $source, string $name): ?string
    {
        return $this->tags($source)[$name] ?? null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (! \is_string($value) && ! \is_numeric($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' || $value === 'N/A' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function integer(array $source, string $key): ?int
    {
        $value = $this->string($source, $key);

        return $value === null ? null : (int) $value;
    }
}
