<?php

declare(strict_types=1);

namespace Utopia\Video;

use Utopia\Video\Exception\Input;

/**
 * Guards the strings that become file paths and muxer arguments.
 *
 * Names chosen by the caller are joined onto the output directory and handed to
 * a backend as argument values, so a name carrying a path separator would write
 * outside the directory it was given, and one carrying a comma or a space would
 * end the argument it was meant to be part of. Both are rejected here, while the
 * name is still only a string.
 *
 * @internal
 */
final class Name
{
    /** Letters, digits, underscores and hyphens: safe in a path and in argv alike. */
    private const LABEL = '/^[A-Za-z0-9_-]+$/';

    /** A label carrying an extension, such as master.m3u8. */
    private const FILE = '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9]+)+$/';

    /**
     * @throws Input
     */
    public static function label(string $value, string $what): string
    {
        if (\preg_match(self::LABEL, $value) !== 1) {
            throw new Input(
                $what.' "'.$value.'" is not usable as a name; '
                .'letters, digits, underscores and hyphens only',
            );
        }

        return $value;
    }

    /**
     * @throws Input
     */
    public static function file(string $value, string $what): string
    {
        if (\preg_match(self::FILE, $value) !== 1) {
            throw new Input(
                $what.' "'.$value.'" is not usable as a filename; '
                .'expected a plain name carrying an extension, such as "master.m3u8"',
            );
        }

        return $value;
    }
}
