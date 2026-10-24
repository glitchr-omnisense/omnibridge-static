<?php

namespace Omnibridge\Static\Tests\Route;

use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Route\SitemapProvider;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;
use PHPUnit\Framework\TestCase;

final class SitemapProviderTest extends TestCase
{
    private const SITEMAP = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
          <url><loc>https://example.org/</loc></url>
          <url><loc>https://example.org/japon/voyages</loc>
            <xhtml:link rel="alternate" hreflang="fr" href="https://example.org/japon/voyages"/>
            <xhtml:link rel="alternate" hreflang="en" href="https://example.org/japon/travels"/>
          </url>
          <url><loc>https://example.org/search?q=tokyo</loc></url>
          <url><loc>https://elsewhere.example.org/page</loc></url>
        </urlset>
        XML;

    public function testTheSitemapsAddressesAreThePages(): void
    {
        $provider = new SitemapProvider(new CallableRenderer(static fn (StaticPage $page) => '/sitemap.xml' === $page->path ? new StaticResponse(200, self::SITEMAP, 'application/xml') : null));
        $pages = iterator_to_array($provider->pages(), false);

        self::assertSame(['/', '/japon/voyages'], array_map(static fn (StaticPage $page) => $page->path, $pages));
        self::assertSame('fr', $pages[1]->locale);
        self::assertSame(['en' => '/japon/travels'], $pages[1]->alternates);
        self::assertSame(['https://example.org/search?q=tokyo', 'https://elsewhere.example.org/page'], array_keys($provider->skipped()));
    }

    public function testASitemapIndexIsFollowed(): void
    {
        $renderer = new CallableRenderer(static fn (StaticPage $page) => match ($page->path) {
            '/sitemap.xml' => new StaticResponse(200, '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.org/sitemap-1.xml</loc></sitemap></sitemapindex>', 'application/xml'),
            '/sitemap-1.xml' => new StaticResponse(200, self::SITEMAP, 'application/xml'),
            default => null,
        });

        self::assertCount(2, iterator_to_array((new SitemapProvider($renderer))->pages(), false));
    }

    public function testASitemapFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sitemap');
        file_put_contents($file, self::SITEMAP);
        try {
            self::assertCount(2, iterator_to_array((new SitemapProvider(file: $file))->pages(), false));
        } finally {
            unlink($file);
        }
    }
}
