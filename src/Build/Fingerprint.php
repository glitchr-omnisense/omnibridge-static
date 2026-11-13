<?php

namespace Omnibridge\Static\Build;

use Omnibridge\Static\StaticPage;

/**
 * The fingerprints an incremental export compares: of the package's own code
 * (an export made by another version of it is no base for this one), of a
 * list of inputs (files by their content, folders by their files' names and
 * contents, glob patterns by what they match, any other string as it is),
 * and of a page (its address and what it was generated from).
 *
 * A path's own name never enters a file's fingerprint, only its content, and
 * a folder's files enter by their names relative to it: the same checkout
 * gives the same fingerprints wherever it is.
 */
final class Fingerprint
{
    private static ?string $code = null;

    /** The SHA-256 of this package's src/ (each file's path in it and content): another version, another value. */
    public static function code(): string
    {
        if (null === self::$code) {
            self::$code = self::folder(\dirname(__DIR__), '*.php');
        }

        return self::$code;
    }

    /**
     * The SHA-256 of a list of inputs, whatever their order.
     *
     * @param list<string>          $inputs files, folders, glob patterns ("content/news/*.md"), or values (a commit, a version)
     * @param array<string, string> $memo   each input's line, kept by the caller for one export: a folder many
     *                                      pages name is read once
     */
    public static function inputs(array $inputs, array &$memo = []): string
    {
        $lines = [];
        foreach ($inputs as $input) {
            $lines[] = $memo[$input] ??= self::input($input);
        }
        sort($lines, \SORT_STRING);

        return hash('sha256', implode("\n", array_unique($lines)));
    }

    /**
     * What a page is, as a string, or null when its parameters hold anything but scalars and arrays of
     * them (an object, whose state no string can be trusted to show).
     */
    public static function page(StaticPage $page): ?string
    {
        if (!self::plain($page->parameters)) {
            return null;
        }
        try {
            return json_encode([$page->path, $page->route, $page->parameters, $page->action, $page->locale, $page->alternates, $page->headers, $page->sitemap, $page->role], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function input(string $input): string
    {
        if (is_file($input)) {
            return 'file '.hash_file('sha256', $input);
        }
        if (is_dir($input)) {
            return 'folder '.self::folder($input);
        }
        if (false !== strpbrk($input, '*?[')) {
            // The pattern and what it matches, relative to the folder before its first wildcard.
            $slash = strrpos(substr($input, 0, (int) strcspn($input, '*?[')), '/');
            $prefix = false === $slash ? '' : substr($input, 0, $slash + 1);
            $matches = glob($input) ?: [];
            sort($matches, \SORT_STRING);
            $lines = [];
            foreach ($matches as $match) {
                $lines[] = substr($match, \strlen($prefix)).' '.(is_dir($match) ? self::folder($match) : (string) hash_file('sha256', $match));
            }

            return 'glob '.substr($input, \strlen($prefix))."\n".implode("\n", $lines);
        }

        return 'value '.$input;
    }

    /** The SHA-256 of a folder's files (their paths in it and contents), through its symbolic links. */
    private static function folder(string $folder, string $pattern = '*'): string
    {
        $folder = rtrim($folder, '/');
        $lines = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $lines[] = substr($file->getPathname(), \strlen($folder) + 1).' '.hash_file('sha256', $file->getPathname());
            }
        }
        sort($lines, \SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /** @param array<mixed> $values */
    private static function plain(array $values): bool
    {
        foreach ($values as $value) {
            if (\is_array($value) ? !self::plain($value) : !(null === $value || \is_scalar($value))) {
                return false;
            }
        }

        return true;
    }
}
