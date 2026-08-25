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

    /** One word of muxer vocabulary, such as an hls_flags entry. */
    private const WORD = '/^[A-Za-z0-9_]+$/';

    /** Anything but whitespace and the control characters, which end a URI. */
    private const PREFIX = '/^[^\s\x00-\x1f\x7f]+$/D';

    /** One argv token: free-form, but nothing that ends or hides an argument. */
    private const ARGUMENT = '/^[^\x00-\x1f\x7f]+$/D';

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
     * Only the identifiers that can be resolved again are allowed. A name is
     * written by the muxer and read back by Parser\Mpd, so one that only half
     * the pair understands would produce a package whose own manifest points at
     * filenames nothing on disk is called. $ext$ is the exception that proves
     * the rule: it is accepted because the muxer resolves it while writing the
     * manifest, so the parser never meets it. $SubNumber$ is refused because
     * subsegment addressing is neither written nor read here.
     *
     * @throws Input
     */
    public static function template(string $value, string $what): string
    {
        $remaining = \preg_replace(
            '/\$(?:RepresentationID|Number(?:%0?\d*d)?|Bandwidth(?:%0?\d*d)?|'
                .'Time(?:%0?\d*d)?|ext)\$|\$\$/',
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
     * A prefix written in front of every segment reference in a playlist.
     *
     * Free to be a URL or a relative path, so separators are expected. It may
     * not carry whitespace, which would end the URI it is prepended to, and it
     * may not begin with a dash, which would make it read as an option when the
     * muxer is handed it. Empty means no prefix at all, which is the default.
     *
     * @throws Input
     */
    public static function prefix(string $value, string $what): string
    {
        if ($value === '') {
            return $value;
        }

        if (\preg_match(self::PREFIX, $value) !== 1 || \str_starts_with($value, '-')) {
            throw new Input(
                $what.' "'.$value.'" is not usable as a URL prefix; '
                .'expected a URL or path carrying no whitespace',
            );
        }

        return $value;
    }

    /**
     * One word of a backend's own vocabulary, such as a muxer flag.
     *
     * @throws Input
     */
    public static function word(string $value, string $what): string
    {
        if (\preg_match(self::WORD, $value) !== 1) {
            throw new Input(
                $what.' "'.$value.'" is not usable as a single word; '
                .'letters, digits and underscores only',
            );
        }

        return $value;
    }

    /**
     * A value handed to a backend as a single argv token.
     *
     * Looser than the rules above on purpose: an option's own syntax may need
     * separators, spaces and punctuation, so the shape of the value belongs to
     * whoever documented the option. What is checked is only what would break
     * the argument list itself — an empty value, a control character, or a
     * leading dash that would make the value read as another option.
     *
     * @throws Input
     */
    public static function argument(string $value, string $what): string
    {
        if (
            $value === ''
            || \preg_match(self::ARGUMENT, $value) !== 1
            || \str_starts_with($value, '-')
        ) {
            throw new Input(
                $what.' "'.$value.'" is not usable as an argument; '
                .'expected a value carrying no control characters and not beginning with "-"',
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
