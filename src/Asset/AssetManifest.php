<?php

namespace Omnibridge\Static\Asset;

/**
 * The files a front-end build produced, read from its manifest, whichever
 * tool wrote it - the exporter copies them from the public folder, as the
 * bundler fingerprinted them:
 *
 * - Vite's manifest.json (build.manifest): entries with "file", "css",
 *   "assets", relative to Vite's output folder - $base is that folder's
 *   public path ("/build");
 * - Webpack's manifest.json (webpack-manifest-plugin, Symfony Encore's):
 *   logical name => public path ("build/app.js" => "/build/app.3f2a.js");
 * - Encore's entrypoints.json: {"entrypoints": {"app": {"js": [...], "css": [...]}}}.
 *
 * No bundler is required, none is run: a manifest is read, nothing else.
 */
final class AssetManifest
{
    /**
     * The public paths the manifest names, sorted, each once. An address on another
     * host (a CDN) is not one of them.
     *
     * @param string $base the public path a Vite manifest's files are relative to
     *
     * @return list<string>
     */
    public static function read(string $file, string $base = ''): array
    {
        $json = @file_get_contents($file);
        if (false === $json) {
            throw new \RuntimeException(\sprintf('The asset manifest "%s" cannot be read.', $file));
        }

        return self::parse($json, $base);
    }

    /** @return list<string> */
    public static function parse(string $json, string $base = ''): array
    {
        $manifest = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($manifest)) {
            throw new \RuntimeException('An asset manifest is a JSON object.');
        }

        $paths = [];
        if (isset($manifest['entrypoints']) && \is_array($manifest['entrypoints'])) {
            // Encore's entrypoints.json.
            array_walk_recursive($manifest['entrypoints'], static function (mixed $value) use (&$paths): void {
                if (\is_string($value)) {
                    $paths[] = $value;
                }
            });
        } else {
            foreach ($manifest as $entry) {
                if (\is_string($entry)) {
                    // Webpack: the public path itself.
                    $paths[] = $entry;
                } elseif (\is_array($entry) && \is_string($entry['file'] ?? null)) {
                    // Vite: relative to its output folder.
                    $prefix = rtrim($base, '/').'/';
                    $paths[] = $prefix.ltrim($entry['file'], '/');
                    foreach (['css', 'assets'] as $kind) {
                        foreach ((array) ($entry[$kind] ?? []) as $each) {
                            if (\is_string($each)) {
                                $paths[] = $prefix.ltrim($each, '/');
                            }
                        }
                    }
                }
            }
        }

        $local = [];
        foreach ($paths as $path) {
            if (preg_match('~^([a-z][a-z0-9+.-]*:)?//~i', $path)) {
                continue;
            }
            $local[] = '/'.ltrim((string) parse_url($path, \PHP_URL_PATH), '/');
        }
        $local = array_values(array_unique($local));
        sort($local);

        return $local;
    }
}
