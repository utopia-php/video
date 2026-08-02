<?php

declare(strict_types=1);

namespace Utopia\Video\Exception;

use Utopia\Video\Exception;

/**
 * The chosen adapter cannot do what was asked of it, or cannot do it the way
 * it was asked.
 */
class Unsupported extends Exception
{
}
