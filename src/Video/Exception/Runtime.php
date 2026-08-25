<?php

declare(strict_types=1);

namespace Utopia\Video\Exception;

use Throwable;
use Utopia\Video\Exception;

/**
 * Work was attempted and did not finish: a command failed or timed out, a
 * directory could not be created, or an expected artifact was never written.
 */
class Runtime extends Exception
{
    /**
     * @param  list<string>  $command  The argv that failed, when one was run.
     * @param  string  $output  The tail of what the command complained about.
     */
    public function __construct(
        string $message,
        private readonly array $command = [],
        private readonly string $output = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    public function output(): string
    {
        return $this->output;
    }
}
