<?php

namespace Omnibridge\Static\Build;

/**
 * An export made in a staging folder moved into its target: a file whose
 * bytes are already there is left as it is (its date too), any other moved
 * in, and whatever the target holds that the export did not write removed,
 * empty folders with it. The target ends up holding exactly what the
 * staging folder held, whatever it held before: the same tree as an export
 * into an empty folder.
 */
final class Sync
{
    /**
     * @param string $last a file moved after all the others (the report: a target whose report is the new one is whole)
     * @param bool   $all  every file moved in, the target emptied first (a full export)
     *
     * @return array{written: int, unchanged: int, removed: int} the files of the export, but $last
     */
    public static function apply(string $staging, string $target, string $last, bool $all = false): array
    {
        $staging = rtrim($staging, '/');
        $target = rtrim($target, '/');
        $files = self::files($staging);
        $staged = array_fill_keys($files, true);
        $written = $unchanged = $removed = 0;
        if (!is_dir($target) && !mkdir($target, 0o777, true) && !is_dir($target)) {
            throw new \RuntimeException(\sprintf('"%s" cannot be made.', $target));
        }

        // What the target holds that the export did not write (and, for a full export, everything).
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $relative = substr($entry->getPathname(), \strlen($target) + 1);
            if ($entry->isDir() && !$entry->isLink()) {
                if ($all || !isset($staged[$relative.'/'])) {
                    @rmdir($entry->getPathname());
                }
                continue;
            }
            if ($last === $relative && !$all) {
                continue;
            }
            if ($all || !isset($staged[$relative]) || $entry->isLink()) {
                unlink($entry->getPathname());
                if ($last !== $relative && !isset($staged[$relative])) {
                    ++$removed;
                }
            }
        }

        foreach ($files as $relative) {
            if ($last === $relative || str_ends_with($relative, '/')) {
                continue;
            }
            $source = $staging.'/'.$relative;
            $path = $target.'/'.$relative;
            self::folderFor($target, $relative, $removed);
            if (is_dir($path) && !is_link($path)) {
                $removed += self::remove($path);
            }
            if (is_file($path) && filesize($path) === filesize($source) && hash_file('sha256', $path) === hash_file('sha256', $source)) {
                ++$unchanged;
                continue;
            }
            if (!rename($source, $path)) {
                throw new \RuntimeException(\sprintf('"%s" cannot be moved to "%s".', $source, $path));
            }
            ++$written;
        }
        if (is_file($staging.'/'.$last)) {
            rename($staging.'/'.$last, $target.'/'.$last);
        }
        self::remove($staging);
        @rmdir($staging);

        return ['written' => $written, 'unchanged' => $unchanged, 'removed' => $removed];
    }

    /** Removes what a folder holds; returns how many files it held. */
    public static function remove(string $folder): int
    {
        if (!is_dir($folder) || is_link($folder)) {
            return 0;
        }
        $files = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry instanceof \SplFileInfo) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                    ++$files;
                }
            }
        }
        rmdir($folder);

        return $files;
    }

    /** The folders of a file of the export made in the target, a file of an earlier export in their way removed. */
    private static function folderFor(string $target, string $relative, int &$removed): void
    {
        $path = $target;
        $segments = explode('/', $relative);
        array_pop($segments);
        foreach ($segments as $segment) {
            $path .= '/'.$segment;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                ++$removed;
            }
            if (!is_dir($path)) {
                mkdir($path);
            }
        }
    }

    /**
     * The files of a folder, sorted, and each of its folders with a trailing slash.
     *
     * @return list<string>
     */
    private static function files(string $folder): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $files[] = substr($entry->getPathname(), \strlen($folder) + 1).($entry->isDir() ? '/' : '');
            }
        }
        sort($files, \SORT_STRING);

        return $files;
    }
}
