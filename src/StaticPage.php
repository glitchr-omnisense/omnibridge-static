<?php

namespace Omnibridge\Static;

/**
 * One address of the application to export: its path (an absolute URL path,
 * no query string, no fragment), and what is known of it - the route and the
 * parameters it was generated from, the action the route runs, its language
 * and the same page in the other languages, whether the sitemap lists it.
 *
 * A provider (StaticRouteProviderInterface) gives the pages; a renderer
 * (StaticRendererInterface) answers each with a StaticResponse; the exporter
 * writes what comes back.
 */
final class StaticPage
{
    /** An ordinary page. */
    public const PAGE = 'page';

    /** The page a static host shows for an address it does not have (written to 404.html, its status 404 expected). */
    public const NOT_FOUND = 'not-found';

    /** A file the pages refer to (a picture, a style sheet...) found by following their links, written as it is. */
    public const RESOURCE = 'resource';

    /**
     * @param string                $path       the address' path, as the application serves it ("/", "/blog/hello")
     * @param string|null           $route      the route it was generated from, for the diagnostics
     * @param array<string, mixed>  $parameters the route's parameters it was generated with
     * @param string|null           $action     the action the route runs ("Class" for an invokable one, "Class::method" otherwise)
     * @param string|null           $locale     its language, as the application names it
     * @param array<string, string> $alternates the same page in other languages: language => path
     * @param array<string, string> $headers    the request headers to render it with (Accept...), beside the renderer's own
     * @param bool                  $sitemap    whether sitemap.xml lists it
     * @param string                $role       self::PAGE, self::NOT_FOUND or self::RESOURCE
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $route = null,
        public readonly array $parameters = [],
        public readonly ?string $action = null,
        public readonly ?string $locale = null,
        public readonly array $alternates = [],
        public readonly array $headers = [],
        public readonly bool $sitemap = true,
        public readonly string $role = self::PAGE,
    ) {
        if ('' === $path || '/' !== $path[0]) {
            throw new \InvalidArgumentException(\sprintf('A page\'s path is an absolute URL path, "%s" is not.', $path));
        }
        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new \InvalidArgumentException(\sprintf('A static page has no query string nor fragment: "%s". A file is one address, whatever its query.', $path));
        }
    }

    /** The page a static host shows for an unknown address, rendered from an address the application does not serve. */
    public static function notFound(string $path = '/404'): self
    {
        return new self($path, sitemap: false, role: self::NOT_FOUND);
    }

    /** @param array<string, string> $alternates */
    public function withAlternates(array $alternates): self
    {
        return new self($this->path, $this->route, $this->parameters, $this->action, $this->locale, $alternates, $this->headers, $this->sitemap, $this->role);
    }

    /** What the diagnostics call it: the path, and the route it comes from. */
    public function describe(): string
    {
        return null !== $this->route ? \sprintf('%s (%s)', $this->path, $this->route) : $this->path;
    }
}
