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
     * Register a listener for PROGRESS or LOG.
     *
     * @param  callable  $listener
     */
    public function on(string $event, callable $listener): static;
}
