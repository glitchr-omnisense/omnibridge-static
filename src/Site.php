<?php

namespace Omnibridge\Static;

/**
 * What a site exports, declared once (the file the CLI reads returns it):
 * the public origin, the providers of its addresses, its renderer, its
 * public folder and asset manifests, the page a static host shows for an
 * unknown address, the addresses that stay on the dynamic host, and the
 * normalisers that take out of a page what changes at every request.
 * Asset\AssetManifest reads the manifests.
 *
 *     return (new Site('https://example.org'))
 *         ->provide(new PageList(['/', '/about']))
 *         ->renderWith(new CallableRenderer(fn (StaticPage $page) => render($page->path)))
 *         ->publicDir(__DIR__.'/public')
 *         ->assets(__DIR__.'/public/build/manifest.json', '/build')
 *         ->copy('favicon.ico', 'robots.txt')
 *         ->notFound('/404');
 */
final class Site
{
    /** @var list<StaticRouteProviderInterface> */
    private array $providers = [];

    private ?StaticRendererInterface $renderer = null;

    private ?string $publicDir = null;

    /** @var list<array{string, string}> manifest file, base path */
    private array $manifests = [];

    /** @var list<string> patterns of public files (relative to the public folder, fnmatch) */
    private array $copies = [];

    private ?StaticPage $notFound = null;

    /** @var list<string> path patterns (fnmatch) */
    private array $dynamic = [];

    private ?string $dynamicHost = null;

    /** @var list<\Closure(string, StaticPage): string> */
    private array $normalisers = [];

    private bool $followResources = true;

    private bool $sitemap = true;

    /** @var list<string> */
    private array $resourceAttributes = [];

    /** @param string $baseUrl the public origin of the static site ("https://example.org"): the sitemap's addresses, the links taken for internal */
    public function __construct(public readonly string $baseUrl)
    {
        $parts = parse_url($baseUrl);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException(\sprintf('A site\'s base URL is an origin ("https://example.org"), "%s" is not.', $baseUrl));
        }
        if ('' !== trim($parts['path'] ?? '', '/')) {
            throw new \InvalidArgumentException(\sprintf('A site is exported at the root of its origin; "%s" has a path.', $baseUrl));
        }
    }

    /** The scheme, host and port of the base URL ("https://example.org:8443"). */
    public function origin(): string
    {
        $parts = (array) parse_url($this->baseUrl);
        $scheme = \is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : 'https';
        $host = \is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        $port = \is_int($parts['port'] ?? null) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    public function provide(StaticRouteProviderInterface ...$providers): self
    {
        array_push($this->providers, ...array_values($providers));

        return $this;
    }

    public function renderWith(StaticRendererInterface $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    /** The application's public folder: the files the pages refer to are copied from it before any is rendered. */
    public function publicDir(string $publicDir): self
    {
        $this->publicDir = rtrim($publicDir, '/');

        return $this;
    }

    /**
     * A front-end build's manifest (Vite, Webpack, Encore's entrypoints.json): every file it names is
     * copied from the public folder (Asset\AssetManifest).
     *
     * @param string $base the public path a Vite manifest's files are relative to ("/build")
     */
    public function assets(string $manifest, string $base = ''): self
    {
        $this->manifests[] = [$manifest, $base];

        return $this;
    }

    /** Public files copied as they are, by pattern relative to the public folder ("favicon.ico", "images/*"). */
    public function copy(string ...$patterns): self
    {
        array_push($this->copies, ...array_values($patterns));

        return $this;
    }

    /** The address rendered for 404.html: one the application does not serve (its error page, status 404). */
    public function notFound(string $path = '/404'): self
    {
        $this->notFound = StaticPage::notFound($path);

        return $this;
    }

    /**
     * The addresses that cannot be static and stay on the dynamic host: a link or a form to one of them is
     * not a broken link. Given the dynamic host's origin, their links and forms are rewritten to it.
     *
     * @param list<string> $patterns paths, fnmatch patterns ("/login", "/admin/*": "*" crosses slashes)
     */
    public function dynamic(array $patterns, ?string $host = null): self
    {
        array_push($this->dynamic, ...$patterns);
        $this->dynamicHost = null !== $host ? rtrim($host, '/') : $this->dynamicHost;

        return $this;
    }

    /**
     * What changes from one request to the next taken out of each HTML page before it is written (a nonce,
     * a token, a timing): the export stays the same from one build to the next.
     *
     * @param \Closure(string $html, StaticPage $page): string $normaliser
     */
    public function normalise(\Closure $normaliser): self
    {
        $this->normalisers[] = $normaliser;

        return $this;
    }

    /**
     * Whether a file the pages refer to that is not in the public folder (a picture an application route
     * serves) is rendered by the renderer and written too. On by default; off, it is a broken link.
     */
    public function followResources(bool $follow = true): self
    {
        $this->followResources = $follow;

        return $this;
    }

    /**
     * More attributes that name a file the page's scripts load (a gallery's "data-thumb"): followed as a
     * src is, beside the ones Link\Links knows.
     */
    public function resourceAttributes(string ...$attributes): self
    {
        array_push($this->resourceAttributes, ...array_values($attributes));

        return $this;
    }

    /** @return list<string> */
    public function getResourceAttributes(): array
    {
        return $this->resourceAttributes;
    }

    /** Whether sitemap.xml is written (on by default). */
    public function sitemap(bool $sitemap = true): self
    {
        $this->sitemap = $sitemap;

        return $this;
    }

    /** @return list<StaticRouteProviderInterface> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function renderer(): StaticRendererInterface
    {
        return $this->renderer ?? throw new \LogicException('The site names no renderer: Site::renderWith().');
    }

    public function getPublicDir(): ?string
    {
        return $this->publicDir;
    }

    /** @return list<array{string, string}> manifest file, base path */
    public function manifests(): array
    {
        return $this->manifests;
    }

    /** @return list<string> */
    public function copies(): array
    {
        return $this->copies;
    }

    public function notFoundPage(): ?StaticPage
    {
        return $this->notFound;
    }

    public function dynamicHost(): ?string
    {
        return $this->dynamicHost;
    }

    /** @return list<\Closure(string, StaticPage): string> */
    public function normalisers(): array
    {
        return $this->normalisers;
    }

    public function followsResources(): bool
    {
        return $this->followResources;
    }

    public function writesSitemap(): bool
    {
        return $this->sitemap;
    }

    public function isDynamic(string $path): bool
    {
        foreach ($this->dynamic as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
