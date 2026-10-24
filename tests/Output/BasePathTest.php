<?php

namespace Omnibridge\Static\Tests\Output;

use Omnibridge\Static\Output\BasePath;
use PHPUnit\Framework\TestCase;

final class BasePathTest extends TestCase
{
    private BasePath $base;

    protected function setUp(): void
    {
        $this->base = new BasePath('https://owner.github.io', '/repo');
    }

    public function testAnAddressIsUnderTheBasePathOrNot(): void
    {
        self::assertTrue($this->base->contains('/repo'));
        self::assertTrue($this->base->contains('/repo/'));
        self::assertTrue($this->base->contains('/repo/x?y'));
        self::assertTrue($this->base->contains('/repo?q'));
        self::assertFalse($this->base->contains('/repository'));
        self::assertFalse($this->base->contains('/x'));
        self::assertSame('/repo/x', $this->base->prefix('/x'));
        self::assertSame('/repo/', $this->base->prefix('/'));
        self::assertSame('/repo/x', $this->base->prefix('/repo/x'), 'already under it');
        self::assertSame('/x', $this->base->strip('/repo/x'));
        self::assertSame('/', $this->base->strip('/repo'));
        self::assertSame('/?q', $this->base->strip('/repo?q'));
        self::assertSame('/repository', $this->base->strip('/repository'));
    }

    public function testAFileIsKnownByItsExtension(): void
    {
        self::assertSame(BasePath::HTML, BasePath::kind('a/index.html'));
        self::assertSame(BasePath::CSS, BasePath::kind('build/app.css'));
        self::assertSame(BasePath::JSON, BasePath::kind('search.json'));
        self::assertSame(BasePath::JSON, BasePath::kind('site.webmanifest'));
        self::assertSame(BasePath::TEXT, BasePath::kind('news/feed.atom'));
        self::assertSame(BasePath::TEXT, BasePath::kind('robots.txt'));
        self::assertNull(BasePath::kind('build/app.js'), 'what a script builds is not read');
        self::assertNull(BasePath::kind('photo.jpg'));
    }

    public function testEveryInternalAddressOfAPageIsMoved(): void
    {
        $html = '<!DOCTYPE html><html><head>'
            .'<link rel="canonical" href="https://owner.github.io/blog/hello">'
            .'<link rel="alternate" hreflang="fr" href="https://owner.github.io/fr/blog/bonjour">'
            .'<link rel="stylesheet" href="/build/app.css"><script src="/build/app.js" defer></script>'
            .'<meta property="og:url" content="https://owner.github.io/blog/hello">'
            .'<script type="application/ld+json">{"url":"https:\/\/owner.github.io\/blog\/hello"}</script>'
            .'<script>fetch("/api"); var a = "<a href=\"/x\">";</script>'
            .'<style>body { background: url(/media/bg.png) }</style>'
            .'</head><body>'
            .'<a href="/">Home</a> <a href="/blog/hello?page=2#top">2</a> <a href="relative">r</a> <a href="#top">top</a>'
            .'<a href="/repo/blog/hello">already</a> <a href="https://elsewhere.example.org/x">out</a> <a href="mailto:a@b.c">m</a>'
            .'<a href="https://example.social/share?url=https%3A%2F%2Fowner.github.io%2Fblog%2Fhello">share</a>'
            .'<form action="/search" method="get"></form>'
            .'<img src="/media/a.jpg" srcset="/media/a.jpg 1x, /media/a@2x.jpg 2x" alt=""> <video poster="/media/p.jpg"></video>'
            .'<div style="background-image: url(\'/media/b.png\')" data-index="/search.json"></div>'
            .'<svg><use href="/sprite.svg#icon"></use></svg>'
            .'<!-- <a href="/commented"> -->'
            .'<p>https://owner.github.io</p>'
            .'</body></html>';

        $moved = $this->base->apply(BasePath::HTML, $html, static fn (): bool => false, null, ['data-index']);

        foreach ([
            '<link rel="canonical" href="https://owner.github.io/repo/blog/hello">',
            'hreflang="fr" href="https://owner.github.io/repo/fr/blog/bonjour"',
            '<link rel="stylesheet" href="/repo/build/app.css"><script src="/repo/build/app.js" defer></script>',
            'content="https://owner.github.io/repo/blog/hello"',
            '{"url":"https:\/\/owner.github.io\/repo\/blog\/hello"}',
            'fetch("/api"); var a = "<a href=\"/x\">";',
            'url(/repo/media/bg.png)',
            '<a href="/repo/">Home</a> <a href="/repo/blog/hello?page=2#top">2</a> <a href="relative">r</a> <a href="#top">top</a>',
            '<a href="/repo/blog/hello">already</a> <a href="https://elsewhere.example.org/x">out</a> <a href="mailto:a@b.c">m</a>',
            'url=https%3A%2F%2Fowner.github.io%2Frepo%2Fblog%2Fhello',
            '<form action="/repo/search" method="get">',
            'src="/repo/media/a.jpg" srcset="/repo/media/a.jpg 1x, /repo/media/a@2x.jpg 2x"',
            'poster="/repo/media/p.jpg"',
            'url(&apos;/repo/media/b.png&apos;)',
            'data-index="/repo/search.json"',
            '<use href="/repo/sprite.svg#icon">',
            '<!-- <a href="/commented"> -->',
            '<p>https://owner.github.io/repo</p>',
        ] as $expected) {
            self::assertStringContainsString($expected, $moved);
        }
        self::assertSame($moved, $this->base->apply(BasePath::HTML, $moved, static fn (): bool => false, null, ['data-index']), 'moved once');
        self::assertSame([], $this->base->escapes(BasePath::HTML, $moved, static fn (): bool => false, ['data-index']));
    }

    public function testThePageForUnknownAddressesResolvesItsRelativeAddresses(): void
    {
        $moved = $this->base->apply(BasePath::HTML, '<a href="blog/hello">a</a> <a href="../x#y">b</a> <a href="#top">c</a> <a href="/">d</a>', static fn (): bool => false, '/errors/404');

        self::assertSame('<a href="/repo/errors/blog/hello">a</a> <a href="/repo/x#y">b</a> <a href="#top">c</a> <a href="/repo/">d</a>', $moved);

        $root = new BasePath('https://example.org', '');
        self::assertSame('<a href="/blog/hello">a</a>', $root->apply(BasePath::HTML, '<a href="blog/hello">a</a>', static fn (): bool => false, '/404'));
    }

    public function testAStyleSheetsAddressesAreMoved(): void
    {
        $css = '/* url(/commented.png) */ @import "/build/other.css"; @font-face { src: url("/build/a.woff2") format("woff2"), url(a.woff) } .x { background: url(data:image/png;base64,AAA) }';

        self::assertSame(
            '/* url(/commented.png) */ @import "/repo/build/other.css"; @font-face { src: url("/repo/build/a.woff2") format("woff2"), url(a.woff) } .x { background: url(data:image/png;base64,AAA) }',
            $this->base->apply(BasePath::CSS, $css, static fn (): bool => false),
        );
    }

    public function testJsonMovesTheAddressesOfTheExportOnly(): void
    {
        $exported = static fn (string $path): bool => \in_array($path, ['/', '/guides/start', '/build/app.css'], true);
        $json = '{"entries":[{"u":"/guides/start","v":"\/guides\/start#install","w":"/guides/start?q=1","x":"/not/exported","y":"/","z":"/repo/guides/start"}],'
            .'"/guides/start": "a key", "abs": "https:\/\/owner.github.io\/guides\/start", "plain": "https://owner.github.io/build/app.css"}';

        $moved = $this->base->apply(BasePath::JSON, $json, $exported);

        self::assertSame(
            '{"entries":[{"u":"/repo/guides/start","v":"\/repo\/guides\/start#install","w":"/repo/guides/start?q=1","x":"/not/exported","y":"/repo/","z":"/repo/guides/start"}],'
            .'"/guides/start": "a key", "abs": "https:\/\/owner.github.io\/repo\/guides\/start", "plain": "https://owner.github.io/repo/build/app.css"}',
            $moved,
        );
        self::assertJson($moved);
        self::assertSame([], $this->base->escapes(BasePath::JSON, $moved, $exported));
        self::assertSame(['/guides/start', 'https://owner.github.io/x'], $this->base->escapes(BasePath::JSON, '{"u":"/guides/start","x":"/not/exported","a":"https://owner.github.io/x"}', $exported));
    }

    public function testFeedsAndTextFilesMoveTheirAbsoluteUrls(): void
    {
        $atom = '<feed><id>https://owner.github.io/news</id><link href="https://owner.github.io/news/feed.atom" rel="self"/><link href="https://owner.github.io.evil.example/x"/><link href="https://owner.github.io:8443/x"/></feed>';

        self::assertSame(
            '<feed><id>https://owner.github.io/repo/news</id><link href="https://owner.github.io/repo/news/feed.atom" rel="self"/><link href="https://owner.github.io.evil.example/x"/><link href="https://owner.github.io:8443/x"/></feed>',
            $this->base->apply(BasePath::TEXT, $atom, static fn (): bool => false),
        );
        self::assertSame("Sitemap: https://owner.github.io/repo/sitemap.xml\n", $this->base->apply(BasePath::TEXT, "Sitemap: https://owner.github.io/sitemap.xml\n", static fn (): bool => false));
    }

    public function testWhatIsLeftOutsideTheBasePathIsFound(): void
    {
        $html = '<a href="/repo/x">in</a> <a href="/x">out</a> <img src="//owner.github.io/y.png"> <link href="https://owner.github.io/z"> <a href="https://owner.github.io/repo/w">in</a> <a href="relative">in</a>';

        self::assertSame(['/x', '//owner.github.io/y.png', 'https://owner.github.io/z'], $this->base->escapes(BasePath::HTML, $html, static fn (): bool => false));
        self::assertStringContainsString('<img src="//owner.github.io/repo/y.png">', $this->base->apply(BasePath::HTML, $html, static fn (): bool => false));
        self::assertSame([], (new BasePath('https://owner.github.io', ''))->escapes(BasePath::HTML, $html, static fn (): bool => false), 'at the root, nothing escapes');
        self::assertSame('2 address(es) outside /repo/: /x, https://owner.github.io/z', $this->base->describe(['/x', 'https://owner.github.io/z']));
    }

    public function testABasePathIsAFolder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BasePath('https://owner.github.io', 'repo/');
    }
}
