<?php

namespace Omnibridge\Static\Route;

use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticRendererInterface;
use Omnibridge\Static\StaticRouteProviderInterface;

/**
 * The pages an application's own sitemap lists (sitemaps.org's urlset, its
 * xhtml:link alternates): a site that already enumerates its addresses for
 * the crawlers enumerates them for the export too. The sitemap is read from
 * a file, or rendered at build time by the renderer (its path, "/sitemap.xml"
 * by default). A sitemap index (sitemapindex) is followed, one level.
 *
 *     new SitemapProvider(renderer: $renderer);
 *     new SitemapProvider(file: 'public/sitemap.xml');
 *
 * An address with a query string, or on another host than the sitemap's
 * first, is left out and said so (skipped()).
 */
final class SitemapProvider implements StaticRouteProviderInterface, SkipReportingInterface
{
    /** @var array<string, string> */
    private array $skipped = [];

    public function __construct(
        private readonly ?StaticRendererInterface $renderer = null,
        private readonly string $path = '/sitemap.xml',
        private readonly ?string $file = null,
    ) {
        if (null === $renderer && null === $file) {
            throw new \InvalidArgumentException('A sitemap is read from a file or rendered: give one of them.');
        }
    }

    public function pages(): iterable
    {
        $this->skipped = [];
        $host = null;
        foreach ($this->entries($this->read($this->path)) as [$loc, $alternates]) {
            $parts = parse_url($loc);
            if (false === $parts) {
                $this->skipped[$loc] = 'not a URL';
                continue;
            }
            $origin = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
            $host ??= $origin;
            if ($origin !== $host) {
                $this->skipped[$loc] = \sprintf('on another host than %s', $host);
                continue;
            }
            if (isset($parts['query'])) {
                $this->skipped[$loc] = 'a query string: one file cannot answer it';
                continue;
            }
            $paths = [];
            foreach ($alternates as $locale => $href) {
                $path = parse_url($href, \PHP_URL_PATH);
                if (\is_string($path) && '' !== $path) {
                    $paths[$locale] = $path;
                }
            }
            $path = ($parts['path'] ?? '') ?: '/';
            $locale = array_search($path, $paths, true);
            unset($paths[\is_string($locale) ? $locale : '']);
            ksort($paths);
            yield new StaticPage($path, locale: \is_string($locale) ? $locale : null, alternates: $paths);
        }
    }

    public function skipped(): array
    {
        return $this->skipped;
    }

    private function read(string $path): string
    {
        if (null !== $this->file && $path === $this->path) {
            $xml = @file_get_contents($this->file);
            if (false === $xml) {
                throw new \RuntimeException(\sprintf('The sitemap "%s" cannot be read.', $this->file));
            }

            return $xml;
        }
        if (null === $this->renderer) {
            throw new \RuntimeException(\sprintf('The sitemap "%s" names "%s", which only a renderer can give.', $this->file, $path));
        }
        $response = $this->renderer->render(new StaticPage($path, sitemap: false, role: StaticPage::RESOURCE));
        if (!$response->isSuccessful()) {
            throw new \RuntimeException(\sprintf('The sitemap %s answered %d.', $path, $response->status));
        }

        return $response->body;
    }

    /** @return iterable<array{string, array<string, string>}> loc, alternates (hreflang => href) */
    private function entries(string $xml, int $depth = 0): iterable
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if (false === $document) {
            throw new \RuntimeException('The sitemap is not XML.');
        }

        if ('sitemapindex' === $document->getName() && 0 === $depth) {
            foreach ($document->children() as $sitemap) {
                $loc = trim((string) $sitemap->loc);
                $path = parse_url($loc, \PHP_URL_PATH);
                if (\is_string($path)) {
                    yield from $this->entries($this->read($path), 1);
                }
            }

            return;
        }

        foreach ($document->children() as $url) {
            $loc = trim((string) $url->loc);
            if ('' === $loc) {
                continue;
            }
            $alternates = [];
            foreach ($url->children('http://www.w3.org/1999/xhtml') as $link) {
                $attributes = $link->attributes();
                if (null !== $attributes && 'alternate' === (string) $attributes['rel'] && '' !== (string) $attributes['hreflang']) {
                    $alternates[(string) $attributes['hreflang']] = (string) $attributes['href'];
                }
            }
            yield [$loc, $alternates];
        }
    }
}
