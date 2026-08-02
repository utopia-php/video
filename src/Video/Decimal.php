<?php

declare(strict_types=1);

namespace Utopia\Video;

/**
 * Formats a number the way command lines want it: no trailing zeroes, and no
 * decimal point at all when the value is whole.
 *
 * @internal
 */
trait Decimal
{
    protected static function number(float $value): string
    {
        if ((float) (int) $value === $value) {
            return (string) (int) $value;
        }

        return \rtrim(\rtrim(\sprintf('%.3F', $value), '0'), '.');
    }
}
