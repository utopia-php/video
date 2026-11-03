<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * What can be handed to a backend as a source.
 *
 * A file on this machine, or an http(s) URL the backend reads itself. ffmpeg
 * and ffprobe seek a URL with range requests, so a source in object storage is
 * encoded, probed and tiled without first being copied to local disk. Nothing
 * else is accepted: other schemes would let a caller point the backend at any
 * protocol it happens to support.
 *
 * @internal
 */
final class Source
{
    private const REMOTE = '#^https?://[^\s\x00-\x1F\x7F]+$#i';

    /**
     * Anything that starts like `name:` is read by ffmpeg as a protocol, and by
     * PHP's own file functions as a stream wrapper, whatever is on disk. One
     * letter is let through, because that is a Windows drive.
     */
    private const PROTOCOL = '/^[A-Za-z][A-Za-z0-9+.\-]+:/';

    public static function reachable(string $path): bool
    {
        return self::remote($path) || self::local($path);
    }

    public static function remote(string $path): bool
    {
        return \preg_match(self::REMOTE, $path) === 1;
    }

    /**
     * How a source is named in a message. A URL loses its query string, which
     * for a presigned link is the credential that lets anyone read the object.
     */
    public static function name(string $path): string
    {
        if (! self::remote($path)) {
            return $path;
        }

        $query = \strpos($path, '?');

        return $query === false ? $path : \substr($path, 0, $query);
    }

    /**
     * A file on this machine, named so that neither PHP nor ffmpeg reads it as
     * something else. Checked before is_file(), which would otherwise follow a
     * wrapper such as ftp:// out to the network just to answer.
     */
    public static function local(string $path): bool
    {
        return $path !== ''
            && ! \str_contains($path, "\0")
            && \preg_match(self::PROTOCOL, $path) !== 1
            && \is_file($path);
    }
}
