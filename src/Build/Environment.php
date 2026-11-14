<?php

namespace Omnibridge\Static\Build;

/**
 * What a rendering depends on that no site declares and the exporter can
 * still see: the PHP version, the loaded extensions with their versions,
 * the packages Composer installed (their version and reference; the root
 * package aside, whose reference is the checkout's commit and whose code is
 * seen through the files it loads), and the PHP files the process loaded.
 * An export whose environment differs from the one its cache was filled in
 * is a full one (Build\Record).
 */
final class Environment
{
    /**
     * The PHP version, the extensions and the installed packages, sorted.
     *
     * @return array{php: string, extensions: array<string, string>, packages: array<string, string>}
     */
    public static function current(): array
    {
        $extensions = [];
        foreach (get_loaded_extensions() as $name) {
            $extensions[strtolower($name)] = (string) phpversion($name);
        }
        ksort($extensions, \SORT_STRING);

        $packages = [];
        if (class_exists(\Composer\InstalledVersions::class)) {
            foreach (\Composer\InstalledVersions::getAllRawData() as $data) {
                $root = $data['root']['name'];
                foreach ($data['versions'] as $name => $version) {
                    // A name only replaced or provided has no version of its own.
                    if ($name === $root || !isset($version['pretty_version'])) {
                        continue;
                    }
                    $packages[$name] = $version['pretty_version'].'@'.($version['reference'] ?? '');
                }
            }
        }
        ksort($packages, \SORT_STRING);

        return ['php' => \PHP_VERSION, 'extensions' => $extensions, 'packages' => $packages];
    }

    /**
     * The PHP files this process loaded, each with its SHA-256 (null: gone since), sorted; those an
     * fnmatch pattern of $ignore matches left out.
     *
     * @param list<string> $ignore
     *
     * @return array<string, string|null>
     */
    public static function loaded(array $ignore = []): array
    {
        $files = [];
        foreach (get_included_files() as $file) {
            if (!self::ignored($file, $ignore)) {
                $files[$file] = is_file($file) ? (string) hash_file('sha256', $file) : null;
            }
        }
        ksort($files, \SORT_STRING);

        return $files;
    }

    /** @param list<string> $ignore */
    public static function ignored(string $file, array $ignore): bool
    {
        foreach ($ignore as $pattern) {
            if (fnmatch($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What changed between two environments, in words, or null when nothing did.
     *
     * @param array{php: string, extensions: array<string, string>, packages: array<string, string>} $earlier
     * @param array{php: string, extensions: array<string, string>, packages: array<string, string>} $now
     */
    public static function difference(array $earlier, array $now): ?string
    {
        if ($earlier['php'] !== $now['php']) {
            return \sprintf('PHP %s, the earlier export ran on %s', $now['php'], $earlier['php']);
        }
        if (null !== $names = self::changed($earlier['extensions'], $now['extensions'])) {
            return 'the PHP extensions changed: '.$names;
        }
        if (null !== $names = self::changed($earlier['packages'], $now['packages'])) {
            return 'the installed packages changed: '.$names;
        }

        return null;
    }

    /**
     * The names whose value differs, is new or is gone (the first three), or null.
     *
     * @param array<string, string> $earlier
     * @param array<string, string> $now
     */
    private static function changed(array $earlier, array $now): ?string
    {
        $names = [];
        foreach ($earlier + $now as $name => $value) {
            if (($earlier[$name] ?? null) !== ($now[$name] ?? null)) {
                $names[] = (string) $name;
            }
        }
        if (!$names) {
            return null;
        }
        sort($names, \SORT_STRING);

        return implode(', ', \array_slice($names, 0, 3)).(\count($names) > 3 ? \sprintf(' and %d more', \count($names) - 3) : '');
    }
}
