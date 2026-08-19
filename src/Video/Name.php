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

    /** A plain filename carrying FFmpeg's DASH placeholders. */
    private const TEMPLATE = '/^[A-Za-z0-9_.%$-]+$/';

    /** A conservative BCP-47 language tag, safe in a muxer list and playlist. */
    private const LANGUAGE = '/^[A-Za-z0-9]{1,8}(?:-[A-Za-z0-9]{1,8})*$/';

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

    /**
     * A DASH segment filename template. It may carry the placeholders FFmpeg
     * understands, but never a directory component.
     *
     * @throws Input
     */
    public static function template(string $value, string $what): string
    {
        $remaining = \preg_replace(
            '/\$(?:RepresentationID|Number(?:%0?\d*d)?|Bandwidth(?:%0?\d*d)?|'
                .'Time(?:%0?\d*d)?|SubNumber(?:%0?\d*d)?|ext)\$|\$\$/',
            '',
            $value,
        );

        if (
            $value === ''
            || \preg_match(self::TEMPLATE, $value) !== 1
            || $remaining === null
            || \str_contains($remaining, '$')
            || $value === '.'
            || $value === '..'
        ) {
            throw new Input(
                $what.' "'.$value.'" is not usable as a DASH filename template; '
                .'expected a plain filename with supported $...$ placeholders',
            );
        }

        return $value;
    }

    /**
     * Optional metadata is omitted when it cannot safely be represented.
     */
    public static function language(string $value): string
    {
        if ($value === '' || \strcasecmp($value, 'und') === 0) {
            return '';
        }

        return \preg_match(self::LANGUAGE, $value) === 1 ? $value : '';
    }
}
