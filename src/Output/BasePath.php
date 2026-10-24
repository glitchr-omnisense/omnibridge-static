<?php

namespace Omnibridge\Static\Output;

use Omnibridge\Static\Link\Links;

/**
 * The folder of its origin a static site is served under ("/repository" on
 * https://owner.github.io/repository/), and the moving of an export's
 * internal addresses under it. The application renders its pages at the root
 * of the origin; once they are written, every address that names the site is
 * moved under the base path:
 *
 * - HTML: every address Link\Links reads that starts with "/" (a link, a
 *   form, a src, a srcset, a poster, a <link>, a style's url(), a refresh,
 *   the attributes Site::resourceAttributes() adds) gets the base path; in a
 *   page that may be served at another address than its own (404.html,
 *   served at any depth; on GitHub Pages, every page, which /a/b redirects to
 *   /a/b/) a relative address is resolved against the address it was
 *   rendered at first.
 * - CSS: its url()s and @imports.
 * - JSON (and .webmanifest): a string value that is the root-relative
 *   address of a page or file of the export ("/guides/start").
 * - Everywhere (HTML, CSS, JSON, XML feeds, sitemaps, robots.txt, SVG): an
 *   absolute URL of the origin ("https://owner.github.io/x", as written, in
 *   JSON's escaped form, or percent-encoded in a share link's query), the
 *   canonical and hreflang links among them.
 *
 * An address already under the base path is left as it is: an application
 * that knows its base path (a bundler's public path) writes them so. Not
 * read: what a script builds at run time (fetch("/api")) - a script reads its
 * addresses from the page (a data- attribute named by
 * Site::resourceAttributes(), document.baseURI) to be moved with it.
 *
 * escapes() lists the addresses of a written file that still point outside
 * the base path: the exporter's check, and the command line's.
 */
final class BasePath
{
    public const HTML = 'html';
    public const CSS = 'css';
    public const JSON = 'json';
    public const TEXT = 'text';

    private const KINDS = [
        'html' => self::HTML, 'htm' => self::HTML, 'xhtml' => self::HTML,
        'css' => self::CSS,
        'json' => self::JSON, 'webmanifest' => self::JSON,
        'xml' => self::TEXT, 'atom' => self::TEXT, 'rss' => self::TEXT, 'xsl' => self::TEXT, 'txt' => self::TEXT, 'svg' => self::TEXT,
    ];

    private const SKIPPED_SCHEMES = '~^[a-z][a-z0-9+.-]*:~i';

    /**
     * @param string $origin the site's scheme, host and port ("https://owner.github.io")
     * @param string $path   the folder, without its trailing slash ("/repository"), or "" at the root
     */
    public function __construct(public readonly string $origin, public readonly string $path)
    {
        if ('' !== $path && ('/' !== $path[0] || str_ends_with($path, '/'))) {
            throw new \InvalidArgumentException(\sprintf('A base path starts with a slash and does not end with one ("/repository"), "%s" does not.', $path));
        }
    }

    public function isRoot(): bool
    {
        return '' === $this->path;
    }

    /** Whether an address' path is the base path or under it ("/repository", "/repository/x?y"). */
    public function contains(string $path): bool
    {
        if ($this->isRoot() || $path === $this->path) {
            return true;
        }

        return str_starts_with($path, $this->path) && 1 === preg_match('~^[/?#]~', substr($path, \strlen($this->path)));
    }

    /** An address under the base path as the application serves it ("/repository/x" => "/x"). */
    public function strip(string $path): string
    {
        if ($this->isRoot() || !$this->contains($path)) {
            return $path;
        }
        $rest = substr($path, \strlen($this->path));

        return '' === $rest || '/' !== $rest[0] ? '/'.$rest : $rest;
    }

    /** A root-relative address moved under the base path ("/x" => "/repository/x"), unless it is already. */
    public function prefix(string $path): string
    {
        return $this->contains($path) ? $path : $this->path.$path;
    }

    /** What a file of the export is, by its extension: self::HTML, CSS, JSON, TEXT, or null (a script, a picture...). */
    public static function kind(string $file): ?string
    {
        return self::KINDS[strtolower(pathinfo($file, \PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * A written file with its internal addresses moved under the base path.
     *
     * @param string                $kind         self::kind() of the file
     * @param \Closure(string): bool $exported     whether a root-relative path names a page or file of the export (for JSON)
     * @param string|null           $resolveFrom  the address a relative address is resolved against first (404.html: the address it was rendered at)
     * @param list<string>          $resources    Site::resourceAttributes()
     */
    public function apply(string $kind, string $body, \Closure $exported, ?string $resolveFrom = null, array $resources = []): string
    {
        $reference = fn (string $address): ?string => $this->reference($address, $resolveFrom);
        $body = match ($kind) {
            self::HTML => Links::rewriteAddresses($body, static fn (string $address): ?string => $reference($address), $resources),
            self::CSS => Links::rewriteCss($body, $reference),
            self::JSON => $this->json($body, $exported),
            default => $body,
        };

        return $this->absolute($body);
    }

    /**
     * The addresses of a written file that point outside the base path: root-relative addresses (and, in
     * JSON, the root-relative addresses of the export's pages and files) and absolute URLs of the origin.
     *
     * @param \Closure(string): bool $exported
     * @param list<string>          $resources
     *
     * @return list<string> each once, in the order they come
     */
    public function escapes(string $kind, string $body, \Closure $exported, array $resources = []): array
    {
        if ($this->isRoot()) {
            return [];
        }
        $found = [];
        $references = match ($kind) {
            self::HTML => array_column(Links::html($body, $resources), 1),
            self::CSS => array_column(Links::css($body), 1),
            self::JSON => $this->jsonPaths($body, $exported),
            default => [],
        };
        foreach ($references as $reference) {
            $reference = trim($reference);
            if (str_starts_with($reference, '/') && !str_starts_with($reference, '//') && !$this->contains($reference)) {
                $found[$reference] = true;
            } elseif (str_starts_with($reference, '//') && null !== $moved = $this->reference($reference, null)) {
                if ($moved !== $reference) {
                    $found[$reference] = true;
                }
            }
        }
        foreach ($this->absolutePatterns() as [$pattern]) {
            if (preg_match_all($pattern.'(?:[^\s"\'<>()\\\\]*+)~i', $body, $matches)) {
                foreach ($matches[0] as $match) {
                    $found[$match] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($found));
    }

    /**
     * What escapes() found, in a line.
     *
     * @param list<string> $escapes
     */
    public function describe(array $escapes): string
    {
        $shown = \array_slice($escapes, 0, 5);

        return \sprintf('%d address(es) outside %s/: %s%s', \count($escapes), $this->path, implode(', ', $shown), \count($escapes) > 5 ? ', ...' : '');
    }

    /** The new address of one address read in HTML or CSS, or null to leave it. */
    private function reference(string $address, ?string $resolveFrom): ?string
    {
        if ('' === $address || '#' === $address[0]) {
            return null;
        }
        if (str_starts_with($address, '//')) {
            // Protocol-relative: the origin's host only (the absolute URLs are moved by absolute()).
            $host = parse_url('https:'.$address, \PHP_URL_HOST);
            $origin = (string) parse_url($this->origin, \PHP_URL_HOST);
            if (!\is_string($host) || strtolower($host) !== $origin || null !== parse_url('https:'.$address, \PHP_URL_PORT) || null !== parse_url($this->origin, \PHP_URL_PORT)) {
                return null;
            }
            $rest = substr($address, 2 + \strlen($host));

            return $this->isRoot() ? null : '//'.$host.$this->prefix('' === $rest ? '/' : $rest);
        }
        if ('/' === $address[0]) {
            return $this->isRoot() ? null : $this->prefix($address);
        }
        if (null === $resolveFrom || preg_match(self::SKIPPED_SCHEMES, $address) || str_contains(rawurldecode($address), '{')) {
            return null;
        }
        // In 404.html, which a static host serves at any depth: resolved, then moved.
        $fragment = '';
        if (false !== $hash = strpos($address, '#')) {
            $fragment = substr($address, $hash);
            $address = substr($address, 0, $hash);
        }

        return $this->prefix(OutputPath::resolve($resolveFrom, $address)).$fragment;
    }

    /** JSON with its string values that name a page or file of the export (root-relative) moved. */
    private function json(string $body, \Closure $exported): string
    {
        if ($this->isRoot()) {
            return $body;
        }

        return (string) preg_replace_callback('~"((?:[^"\\\\]++|\\\\.)*+)"(\s*+:)?~s', function (array $match) use ($exported): string {
            if ('' !== ($match[2] ?? '') || null === $path = $this->jsonPath($match[1], $exported)) {
                return $match[0];
            }
            $escaped = str_starts_with($match[1], '\/');

            return '"'.($escaped ? str_replace('/', '\/', $this->path) : $this->path).$match[1].'"';
        }, $body);
    }

    /**
     * @param \Closure(string): bool $exported
     *
     * @return list<string> the string values of the JSON that are root-relative addresses of the export
     */
    private function jsonPaths(string $body, \Closure $exported): array
    {
        preg_match_all('~"((?:[^"\\\\]++|\\\\.)*+)"(\s*+:)?~s', $body, $matches, \PREG_SET_ORDER);
        $paths = [];
        foreach ($matches as $match) {
            if ('' === ($match[2] ?? '') && null !== $path = $this->jsonPath($match[1], $exported, false)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The address a JSON string literal's content names, if it is a root-relative address of the export
     * (not under the base path already, when $outside).
     *
     * @param \Closure(string): bool $exported
     */
    private function jsonPath(string $literal, \Closure $exported, bool $outside = true): ?string
    {
        if (!str_starts_with($literal, '/') && !str_starts_with($literal, '\/')) {
            return null;
        }
        $value = json_decode('"'.$literal.'"');
        if (!\is_string($value) || str_starts_with($value, '//') || ($outside && $this->contains($value))) {
            return null;
        }
        $path = (string) preg_replace('~[?#].*$~s', '', $value);

        return $exported($path) ? $value : null;
    }

    /** The absolute URLs of the origin moved under the base path, in their three spellings. */
    private function absolute(string $body): string
    {
        if ($this->isRoot()) {
            return $body;
        }
        foreach ($this->absolutePatterns() as [$pattern, $insert]) {
            $body = (string) preg_replace($pattern.'~i', '$0'.str_replace(['\\', '$'], ['\\\\', '\$'], $insert), $body);
        }

        return $body;
    }

    /**
     * The origin as written, as JSON escapes it, and percent-encoded, each not followed by the base path
     * (a pattern without its closing delimiter and flags), and what is inserted after it.
     *
     * @return list<array{string, string}>
     */
    private function absolutePatterns(): array
    {
        $encodedOrigin = str_replace(['%3A', '%2F'], ['%3[Aa]', '%2[Ff]'], preg_quote(rawurlencode($this->origin), '~'));
        $encodedPath = str_replace('%2F', '%2[Ff]', preg_quote(rawurlencode($this->path), '~'));

        return [
            ['~'.preg_quote($this->origin, '~').'(?![\w.:-])(?!'.preg_quote($this->path, '~').'(?:[/?#"\'\s<>)\]\\\\,;&]|$))', $this->path],
            ['~'.preg_quote(str_replace('/', '\/', $this->origin), '~').'(?![\w.:-])(?!'.preg_quote(str_replace('/', '\/', $this->path), '~').'(?:\\\\/|[?#"\'\s<>)\],;&]|\\\\|$))', str_replace('/', '\/', $this->path)],
            ['~'.$encodedOrigin.'(?![\w.-]|%3[Aa])(?!'.$encodedPath.'(?:%2[Ff]|%3[Ff]|%23|[&"\'\s<>]|$))', rawurlencode($this->path)],
        ];
    }
}
