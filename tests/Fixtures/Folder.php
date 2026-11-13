<?php

namespace Omnibridge\Static\Tests\Fixtures;

final class Folder
{
    /** A folder of the tests removed, with what it holds. */
    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * What a folder holds, to compare two: each file's SHA-256 by its path, each folder as "folder".
     *
     * @return array<string, string>
     */
    public static function tree(string $dir): array
    {
        $tree = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $tree[substr($entry->getPathname(), \strlen($dir) + 1)] = $entry->isLink() ? 'link' : ($entry->isDir() ? 'folder' : (string) hash_file('sha256', $entry->getPathname()));
            }
        }
        ksort($tree, \SORT_STRING);

        return $tree;
    }
}
