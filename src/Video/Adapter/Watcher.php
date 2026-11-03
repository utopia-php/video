<?php

declare(strict_types=1);

namespace Utopia\Video\Adapter;

use Closure;
use Utopia\Video\Written;

/**
 * Notices segments as a packaging run finishes them.
 *
 * A muxer writes numbered segments one after another and closes each before it
 * opens the next, so a segment is complete once a higher number exists beside
 * it. Files are grouped by their name with the number taken out, which keeps
 * every rung of a ladder in its own sequence. The last segment of each group,
 * and every initialisation segment, only count once the run has ended.
 *
 * Manifests are never reported: the muxer rewrites them until the end, and the
 * package is read back from them. Neither is anything still carrying the
 * `.tmp` suffix a muxer writes before renaming a finished file.
 *
 * Only what this run writes counts. The directory is noted when the watcher is
 * made, and a file that is still exactly as it was is neither reported nor
 * taken as proof that anything before it is finished: a source kept beside
 * the output must not be handed to a listener that deletes, and a longer
 * earlier run's segments must not release this run's before they are done.
 * A file counts as rewritten when its inode, size or whole-second times
 * change, so one rewritten in the same second at the same size on a reused
 * inode looks untouched: it is not reported, but stays on disk and in the
 * package. A fresh directory per run avoids the question entirely.
 *
 * @internal
 */
final class Watcher
{
    /** Segment names end in a number, then an extension. */
    private const NUMBERED = '/^(.*?)(\d+)(\.[A-Za-z0-9]+)$/';

    private const TEMPORARY = '.tmp';

    /** @var list<string> */
    private const MANIFESTS = ['m3u8', 'mpd'];

    /**
     * Bytes of every file reported so far, by name.
     *
     * @var array<string, int>
     */
    private array $ledger = [];

    /**
     * The highest number seen in each group, kept across scans because a
     * reported file may already be gone.
     *
     * @var array<string, int>
     */
    private array $highest = [];

    /**
     * What was in the directory before the run, by name.
     *
     * @var array<string, string>
     */
    private array $before = [];

    /**
     * @param  Closure(Written): void  $report
     */
    public function __construct(
        private readonly string $dir,
        private readonly Closure $report,
    ) {
        foreach (\scandir($dir) ?: [] as $name) {
            $mark = self::mark($dir.'/'.$name);

            if ($mark !== null) {
                $this->before[$name] = $mark;
            }
        }
    }

    /**
     * Report what has been finished since the last scan.
     *
     * @param  bool  $final  The run has ended, so everything left is finished.
     */
    public function scan(bool $final = false): void
    {
        $found = [];

        foreach (\scandir($this->dir) ?: [] as $name) {
            $path = $this->dir.'/'.$name;

            if ($name[0] === '.' || ! \is_file($path)) {
                continue;
            }

            if (isset($this->before[$name]) && $this->before[$name] === self::mark($path)) {
                continue;
            }

            $temporary = \str_ends_with($name, self::TEMPORARY);
            $plain = $temporary ? \substr($name, 0, -\strlen(self::TEMPORARY)) : $name;
            $group = null;
            $number = null;

            if (\preg_match(self::NUMBERED, $plain, $match) === 1) {
                $group = $match[1].$match[3];
                $number = (int) $match[2];
                $this->highest[$group] = \max($this->highest[$group] ?? $number, $number);
            }

            // A file being written still proves the one before it is done, which
            // is why it counts towards the highest number before it is skipped.
            if ($temporary || $this->manifest($plain) || isset($this->ledger[$plain])) {
                continue;
            }

            $found[] = [$plain, $path, $group, $number];
        }

        foreach ($found as [$name, $path, $group, $number]) {
            $done = $final || (
                $group !== null
                && ! \str_contains(\strtolower($name), 'init')
                && $this->highest[$group] > $number
            );

            if (! $done) {
                continue;
            }

            \clearstatcache(true, $path);
            $size = \filesize($path);

            if ($size === false) {
                continue;
            }

            $this->ledger[$name] = $size;
            ($this->report)(new Written($name, $path, $size));
        }
    }

    /**
     * Bytes of every file reported, by name.
     *
     * @return array<string, int>
     */
    public function ledger(): array
    {
        return $this->ledger;
    }

    /**
     * Enough of a file's state to tell whether it has been written since.
     * A muxer that renames a finished file gives it a new inode, and one that
     * rewrites in place changes its size or modification time.
     */
    private static function mark(string $path): ?string
    {
        \clearstatcache(true, $path);
        $stat = @\stat($path);

        if ($stat === false || ! \is_file($path)) {
            return null;
        }

        return $stat['ino'].':'.$stat['size'].':'.$stat['mtime'].':'.$stat['ctime'];
    }

    private function manifest(string $name): bool
    {
        return \in_array(\strtolower(\pathinfo($name, PATHINFO_EXTENSION)), self::MANIFESTS, true);
    }
}
