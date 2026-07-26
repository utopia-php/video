<?php

declare(strict_types=1);

namespace Utopia\Streaming;

use Utopia\Streaming\Exception\RuntimeException;

final class File
{
    public static function makeDir(string $dirname, int $mode = 0777): void
    {
        if (is_dir($dirname)) {
            return;
        }

        if (! @mkdir($dirname, $mode, true) && ! is_dir($dirname)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $dirname));
        }
    }

    public static function remove(string $path): void
    {
        if ($path === '' || ! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (! @unlink($path)) {
                throw new RuntimeException(sprintf('Unable to remove "%s".', $path));
            }

            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException(sprintf('Unable to read directory "%s".', $path));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::remove($path.DIRECTORY_SEPARATOR.$item);
        }

        if (! @rmdir($path)) {
            throw new RuntimeException(sprintf('Unable to remove directory "%s".', $path));
        }
    }
}
