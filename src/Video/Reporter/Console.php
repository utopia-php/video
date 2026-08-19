<?php

declare(strict_types=1);

namespace Utopia\Video\Reporter;

use Utopia\Console as Terminal;
use Utopia\Video\Reporter;

/**
 * Status lines on the terminal, in green and red.
 *
 * The default, because a green line saying which file was written is exactly
 * what a command line caller wants and costs a caller who does not want it one
 * constructor argument to replace.
 */
final class Console implements Reporter
{
    public function success(string $message): void
    {
        Terminal::success($message);
    }

    public function error(string $message): void
    {
        Terminal::error($message);
    }
}
