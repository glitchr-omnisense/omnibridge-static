<?php

namespace Omnibridge\Static\Output;

/**
 * Where an address is written in the export, and the URL rules the exporter
 * follows: "/" and "/blog/hello" are HTML pages written as index.html in
 * their folder (a static host serves /blog/hello from blog/hello/index.html:
 * nginx's try_files $uri $uri/index.html, the "pretty URLs" of the static
 * hosts); a page whose path ends in .html, and any other file, are written
 * at their path. Paths are percent-decoded: the file of /caf%C3%A9 is
 * café/index.html, as a static host decodes the address before it looks.
 */
final class OutputPath
{
    /** The file an HTML page is written to, relative to the export ("blog/hello/index.html"). */
    public static function page(string $path): string
    {
        $segments = self::segments($path);
        $last = end($segments);
        if (false !== $last && preg_match('~\.html?$~i', $last) && !str_ends_with($path, '/')) {
            return implode('/', $segments);
        }

        return ltrim(implode('/', $segments).'/index.html', '/');
    }

    /** The file any other address is written to, relative to the export ("build/app.css"). */
    public static function file(string $path): string
    {
        $segments = self::segments($path);
        if (!$segments || str_ends_with($path, '/')) {
            throw new \InvalidArgumentException(\sprintf('The address %s names a folder, not a file.', $path));
        }

        return implode('/', $segments);
    }

    /** Whether the last segment of a path has an extension (".css", ".json"). */
    public static function hasExtension(string $path): bool
    {
        $segments = self::segments($path);
        $last = end($segments);

        return false !== $last && 1 === preg_match('~\.[A-Za-z0-9]{1,8}$~', $last);
    }

    /**
     * An address' path as the exporter compares it: percent-decoded, without its trailing slash ("/" stays "/").
     */
    public static function key(string $path): string
    {
        $decoded = rawurldecode($path);

        return '/' === $decoded ? '/' : rtrim($decoded, '/');
    }

    /**
     * A reference resolved against the address of the document it is in (RFC 3986's merge and dot
     * segments): the path and query of the address it names.
     */
    public static function resolve(string $base, string $reference): string
    {
        if (str_starts_with($reference, '?')) {
            $reference = (string) parse_url($base, \PHP_URL_PATH).$reference;
        } elseif (!str_starts_with($reference, '/')) {
            $directory = (string) parse_url($base, \PHP_URL_PATH);
            $directory = substr($directory, 0, (int) strrpos($directory, '/') + 1);
            $reference = $directory.$reference;
        }
        $query = '';
        if (false !== $at = strpos($reference, '?')) {
            $query = substr($reference, $at);
            $reference = substr($reference, 0, $at);
        }

        $output = [];
        $parts = explode('/', $reference);
        foreach ($parts as $index => $segment) {
            if ('.' === $segment) {
                if ($index === \count($parts) - 1) {
                    $output[] = '';
                }
                continue;
            }
            if ('..' === $segment) {
                if (\count($output) > 1) {
                    array_pop($output);
                }
                if ($index === \count($parts) - 1) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }
        $path = implode('/', $output);

        return ('' === $path || '/' !== $path[0] ? '/'.$path : $path).$query;
    }

    /** @return list<string> the decoded segments, checked */
    private static function segments(string $path): array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ('' === $segment) {
                continue;
            }
            $decoded = rawurldecode($segment);
            if ('.' === $decoded || '..' === $decoded || str_contains($decoded, "\0") || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                throw new \InvalidArgumentException(\sprintf('The address %s cannot be a file in the export.', $path));
            }
            $segments[] = $decoded;
        }

        return $segments;
    }
}
