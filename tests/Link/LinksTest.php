<?php

namespace Omnibridge\Static\Tests\Link;

use Omnibridge\Static\Link\Links;
use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase
{
    public function testTheMarkupsAddressesAreReadByKind(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html><html><head>
            <link rel="stylesheet" href="/build/app.css"><link rel=icon href=/favicon.ico>
            <style>.hero { background: url('/images/hero.jpg') }</style>
            <script src="/build/app.js"></script>
            <script>var a = '<a href="/not-a-link">';</script>
            </head><body>
            <!-- <a href="/commented-out">no</a> -->
            <a href="/blog/hello?x=1&amp;y=2#top">Hello</a>
            <img src="/media/a.png" srcset="/media/a-1x.png 1x, /media/a,2x.png 2x" alt="">
            <div style="background-image: url(&quot;/media/b.png&quot;)"></div>
            <form action="/search" method="get"></form><form method="POST"></form>
            <video poster="/media/poster.jpg"></video><img data-src="/media/lazy.jpg" data-srcset="/media/lazy-2x.jpg 2x">
            </body></html>
            HTML;

        self::assertSame([
            [Links::RESOURCE, '/build/app.css'],
            [Links::RESOURCE, '/favicon.ico'],
            [Links::RESOURCE, '/build/app.js'],
            [Links::PAGE, '/blog/hello?x=1&y=2#top'],
            [Links::RESOURCE, '/media/a.png'],
            [Links::RESOURCE, '/media/a-1x.png'],
            [Links::RESOURCE, '/media/a,2x.png'],
            [Links::RESOURCE, '/media/b.png'],
            [Links::FORM, '/search'],
            [Links::POST, ''],
            [Links::RESOURCE, '/media/poster.jpg'],
            [Links::RESOURCE, '/media/lazy.jpg'],
            [Links::RESOURCE, '/media/lazy-2x.jpg'],
            [Links::RESOURCE, '/images/hero.jpg'],
        ], Links::html($html));
    }

    public function testMoreResourceAttributesCanBeNamed(): void
    {
        $html = '<div id="gallery"><a href="/images/big.jpg" data-ngthumb="/images/thumb.jpg"></a></div>';

        self::assertSame([[Links::PAGE, '/images/big.jpg']], Links::html($html));
        self::assertSame([[Links::PAGE, '/images/big.jpg'], [Links::RESOURCE, '/images/thumb.jpg']], Links::html($html, ['data-ngthumb']));
    }

    public function testAStyleSheetsAddressesAreItsUrlsAndImports(): void
    {
        $css = "@import 'base.css'; /* url(/commented.png) */ .a { background: url(img/a.png) } @font-face { src: url(\"../fonts/a.woff2\") format('woff2') }";

        self::assertSame([
            [Links::RESOURCE, 'base.css'],
            [Links::RESOURCE, 'img/a.png'],
            [Links::RESOURCE, '../fonts/a.woff2'],
        ], Links::css($css));
    }

    public function testLinksAndFormsAreRewrittenScriptsAndOtherTagsAreNot(): void
    {
        $html = '<a class="x" href="/login">Sign in</a><link href="/login"><form method="post" action=\'/newsletter\'></form><script>"<a href=\"/login\">"</script><a href="/about">About</a>';

        $rewritten = Links::rewrite($html, static fn (string $address) => \in_array($address, ['/login', '/newsletter'], true) ? 'https://app.example.org'.$address : null);

        self::assertSame('<a class="x" href="https://app.example.org/login">Sign in</a><link href="/login"><form method="post" action="https://app.example.org/newsletter"></form><script>"<a href=\"/login\">"</script><a href="/about">About</a>', $rewritten);
    }

    public function testARefreshAndAnSvgsReferencesAreRead(): void
    {
        self::assertSame([
            [Links::PAGE, '/new'],
            [Links::RESOURCE, '/sprite.svg#a'],
            [Links::RESOURCE, '/b.png'],
        ], Links::html('<meta http-equiv="Refresh" content="0; URL=\'/new\'"><meta name="x" content="0; url=/no"><svg><use xlink:href="/sprite.svg#a"/><image href="/b.png"/></svg>'));
    }

    public function testEveryAddressReadCanBeRewritten(): void
    {
        $html = '<link rel="preload" as="image" imagesrcset="/a.png 1x, /b.png 2x"><script src="/app.js">fetch("/api")</script><style>p { background: url(/c.png) }</style>'
            .'<meta http-equiv="refresh" content="0; url=/new"><a href="/x" title="/x">x</a><a href="  /y ">y</a><form method="post"></form><img data-thumb="/t.png" src=/i.png alt="">';

        $rewritten = Links::rewriteAddresses($html, static fn (string $address, string $kind): ?string => str_starts_with($address, '/') ? '/base'.$address : null, ['data-thumb']);

        self::assertSame('<link rel="preload" as="image" imagesrcset="/base/a.png 1x, /base/b.png 2x"><script src="/base/app.js">fetch("/api")</script><style>p { background: url(/base/c.png) }</style>'
            .'<meta http-equiv="refresh" content="0; url=/base/new"><a href="/base/x" title="/x">x</a><a href="/base/y">y</a><form method="post"></form><img data-thumb="/base/t.png" src="/base/i.png" alt="">', $rewritten);
        self::assertSame($html, Links::rewriteAddresses($html, static fn (string $address): ?string => null), 'nothing changed, byte for byte');
    }
}
