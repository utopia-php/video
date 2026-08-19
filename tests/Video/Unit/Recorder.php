<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Video\Reporter;

/**
 * A reporter that keeps what it was told instead of printing it.
 *
 * Status lines can be asserted on this way, and a suite using it stays off the
 * terminal — which is the same reason a server would supply one of its own.
 */
class Recorder implements Reporter
{
    /** @var list<string> */
    public array $successes = [];

    /** @var list<string> */
    public array $errors = [];

    public function success(string $message): void
    {
        $this->successes[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
