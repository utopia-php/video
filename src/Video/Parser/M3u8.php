<?php

declare(strict_types=1);

namespace Utopia\Video\Parser;

use Utopia\Video\Exception\Runtime;
use Utopia\Video\Segment;
use Utopia\Video\Track;
use Utopia\Video\Variant;

/**
 * Reads HLS playlists back into structured data.
 *
 * A playlist is the packager's own record of what it produced, which makes it
 * the most reliable thing to read: it lists exactly the files a player will
 * ask for, in the order it will ask for them.
 *
 * @internal
 */
final class M3u8
{
    /**
     * Attributes of a master playlist entry, keyed by name.
     *
     * @return array<string, string>
     */
    public static function attributes(string $line): array
    {
        $attributes = [];
        $offset = \strpos($line, ':');

        if ($offset === false) {
            return $attributes;
        }

        \preg_match_all(
            '/([A-Z0-9\-]+)=("[^"]*"|[^,]*)/',
            \substr($line, $offset + 1),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $attributes[$match[1]] = \trim($match[2], '"');
        }

        return $attributes;
    }

    /**
     * Every variant a master playlist advertises, in declaration order.
     *
     * @return list<array{id: string, file: string, type: string, codecs: ?string, bandwidth: int, width: ?int, height: ?int, language: ?string, group: ?string}>
     */
    public static function master(string $path): array
    {
        $lines = self::lines($path);
        $variants = [];
        $pending = null;

        foreach ($lines as $line) {
            if (\str_starts_with($line, '#EXT-X-MEDIA:')) {
                $attributes = self::attributes($line);

                if (($attributes['TYPE'] ?? '') !== 'AUDIO' || ! isset($attributes['URI'])) {
                    continue;
                }

                $variants[] = [
                    'id' => (string) \count($variants),
                    'file' => \basename($attributes['URI']),
                    'type' => Track::AUDIO,
                    'codecs' => null,
                    'bandwidth' => 0,
                    'width' => null,
                    'height' => null,
                    'language' => $attributes['LANGUAGE'] ?? null,
                    'group' => $attributes['GROUP-ID'] ?? null,
                ];

                continue;
            }

            if (\str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $pending = self::attributes($line);

                continue;
            }

            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }

            if ($pending === null) {
                continue;
            }

            [$width, $height] = self::resolution($pending['RESOLUTION'] ?? null);

            $variants[] = [
                'id' => (string) \count($variants),
                'file' => \basename($line),
                'type' => Track::VIDEO,
                'codecs' => $pending['CODECS'] ?? null,
                'bandwidth' => (int) ($pending['BANDWIDTH'] ?? 0),
                'width' => $width,
                'height' => $height,
                'language' => null,
                'group' => $pending['AUDIO'] ?? null,
            ];

            $pending = null;
        }

        return $variants;
    }

    /**
     * Segments of one media playlist, plus its playlist level attributes.
     *
     * @return array{target: float, version: int, segments: list<array{file: string, duration: float, init: bool}>}
     */
    public static function media(string $path): array
    {
        $lines = self::lines($path);
        $segments = [];
        $target = 0.0;
        $version = 0;
        $duration = 0.0;

        foreach ($lines as $line) {
            if (\str_starts_with($line, '#EXT-X-TARGETDURATION:')) {
                $target = (float) \substr($line, 22);

                continue;
            }

            if (\str_starts_with($line, '#EXT-X-VERSION:')) {
                $version = (int) \substr($line, 15);

                continue;
            }

            if (\str_starts_with($line, '#EXT-X-MAP:')) {
                $attributes = self::attributes($line);

                if (isset($attributes['URI'])) {
                    $segments[] = [
                        'file' => \basename($attributes['URI']),
                        'duration' => 0.0,
                        'init' => true,
                    ];
                }

                continue;
            }

            if (\str_starts_with($line, '#EXTINF:')) {
                $duration = (float) \rtrim(\substr($line, 8), ',');

                continue;
            }

            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }

            $segments[] = [
                'file' => \basename($line),
                'duration' => $duration,
                'init' => false,
            ];

            $duration = 0.0;
        }

        return ['target' => $target, 'version' => $version, 'segments' => $segments];
    }

    /**
     * Reads a master playlist and everything it points at.
     *
     * @return array{variants: list<Variant>, metadata: array<string, mixed>, playlists: list<string>}
     */
    public static function read(string $master, string $dir): array
    {
        $dir = \rtrim($dir, '/');
        $variants = [];
        $playlists = [];
        $target = 0.0;
        $version = 0;

        foreach (self::master($master) as $entry) {
            $playlist = $dir.'/'.$entry['file'];

            if (! \is_file($playlist)) {
                throw new Runtime('Playlist "'.$entry['file'].'" is missing from the package');
            }

            $playlists[] = $playlist;
            $media = self::media($playlist);
            $target = \max($target, $media['target']);
            $version = \max($version, $media['version']);

            $segments = [];
            $number = 0;

            foreach ($media['segments'] as $segment) {
                $segments[] = self::segment($entry['id'], $dir, $segment, $number);

                if (! $segment['init']) {
                    $number++;
                }
            }

            $variants[] = new Variant(
                id: $entry['id'],
                type: $entry['type'],
                codecs: $entry['codecs'],
                bandwidth: $entry['bandwidth'],
                width: $entry['width'],
                height: $entry['height'],
                language: $entry['language'],
                target: $media['target'],
                segments: $segments,
                playlist: $playlist,
            );
        }

        return [
            'variants' => $variants,
            'metadata' => ['targetDuration' => $target, 'version' => $version],
            'playlists' => $playlists,
        ];
    }

    /**
     * @param  array{file: string, duration: float, init: bool}  $segment
     */
    private static function segment(string $variant, string $dir, array $segment, int $number): Segment
    {
        $path = $dir.'/'.$segment['file'];

        if (! \is_file($path)) {
            throw new Runtime('Segment "'.$segment['file'].'" is missing from the package');
        }

        return new Segment(
            variant: $variant,
            file: $segment['file'],
            path: $path,
            duration: $segment['duration'],
            init: $segment['init'],
            number: $segment['init'] ? 0 : $number,
            size: (int) \filesize($path),
        );
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function resolution(?string $value): array
    {
        if ($value === null || ! \str_contains($value, 'x')) {
            return [null, null];
        }

        [$width, $height] = \explode('x', $value, 2);

        return [(int) $width, (int) $height];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $path): array
    {
        $body = \is_file($path) ? \file_get_contents($path) : false;

        if ($body === false) {
            throw new Runtime('Unable to read playlist "'.$path.'"');
        }

        $lines = [];

        foreach (\preg_split('/\R/', $body) ?: [] as $line) {
            $lines[] = \trim($line);
        }

        return $lines;
    }
}
