<?php

declare(strict_types=1);

namespace Utopia\Streaming;

final class Utils
{
    public static function appendSlash(string $str): string
    {
        return $str !== '' ? rtrim($str, '/').'/' : $str;
    }

    /**
     * Convert an associative options map to a flat ffmpeg argv list.
     *
     * ['b:v' => '750k', 's:v' => '640x360'] → ['-b:v', '750k', '-s:v', '640x360']
     *
     * If any key is not a string the original array is returned unchanged
     * (already a flat argv list).
     *
     * @param  array<string|int, mixed>  $array
     * @return list<string>
     */
    public static function arrayToFFmpegOpt(array $array, string $startWith = '-'): array
    {
        $out = [];

        foreach ($array as $key => $value) {
            if (! is_string($key)) {
                /** @var list<string> $array */
                return array_map('strval', $array);
            }

            $out[] = $startWith.$key;
            $out[] = (string) $value;
        }

        return $out;
    }
}
