<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

/**
 * A backend that does long-running work and reports on it as it goes.
 */
interface Observable
{
    /** Reports how far along a running job is. Receives a Progress. */
    public const PROGRESS = 'progress';

    /** Raw backend output, useful for debugging. Receives a string. */
    public const LOG = 'log';

    /**
     * A segment the backend has finished writing, while the job is still
     * running. Receives a Written.
     *
     * A listener may move or delete the file: its size is recorded before the
     * event fires, and the package returned at the end still describes it.
     */
    public const SEGMENT = 'segment';

    /**
     * Register a listener for PROGRESS, LOG or SEGMENT.
     *
     * @param  callable  $listener
     */
    public function on(string $event, callable $listener): static;
}
