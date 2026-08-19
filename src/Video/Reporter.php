<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * Where a backend's status lines go.
 *
 * Printing them is right for a command line tool and wrong almost everywhere
 * else: an HTTP worker, a queue consumer or a test suite has its own idea of
 * what a log is and where it belongs, and a library writing to stdout behind
 * their back is a nuisance rather than a feature. So the destination is a seam.
 *
 * Reporter\Console keeps the printing behaviour, and is the default. Anything
 * that would rather route these lines into a PSR-3 logger, a queue, or nowhere
 * at all implements this instead — two methods, no dependency on this library's
 * internals.
 *
 * Note that this covers status lines only. Backend commentary arrives as LOG
 * events and progress as PROGRESS events, both of which already go wherever the
 * caller's listeners send them.
 */
interface Reporter
{
    /**
     * A job finished and wrote what it was asked for.
     */
    public function success(string $message): void;

    /**
     * A backend command failed; the exception follows.
     */
    public function error(string $message): void;
}
