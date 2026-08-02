<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

/**
 * A backend that can say which one it is.
 *
 * The name is a lowercase slug matching the key the factories accept, so a
 * backend chosen from configuration can be identified again afterwards.
 */
interface Named
{
    public function getName(): string;
}
