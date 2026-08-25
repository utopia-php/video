<?php

declare(strict_types=1);

namespace Utopia\Video\Reporter;

use Utopia\Video\Reporter;

/**
 * Throws status lines away.
 *
 * For anywhere stdout belongs to something else — a server, a worker, a test
 * suite. Adapter::QUIET silences a backend completely; this silences only the
 * status lines, so LOG and PROGRESS events keep arriving.
 */
final class Silent implements Reporter
{
    public function success(string $message): void
    {
    }

    public function error(string $message): void
    {
    }
}
