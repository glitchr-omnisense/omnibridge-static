<?php

namespace Omnibridge\Static\Tests;

use Omnibridge\Static\Console\Cli;
use Omnibridge\Static\Diagnostic;
use Omnibridge\Static\Exporter;
use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;
use Omnibridge\Static\Tests\Fixtures\Folder;
use PHPUnit\Framework\TestCase;

/**
 * A site served under a folder of its origin (a GitHub Pages project site,
 * https://owner.github.io/repo/): rendered at the root, its export moved
 * under /repo, with the files GitHub Pages reads.
 */
final class GitHubPagesTest extends TestCase
{
    private string $public;
    private string $out;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir().'/omnibridge-static-pages-public-'.getmypid();
        $this->out = sys_get_temp_dir().'/omnibridge-static-pages-out-'.getmypid();
        foreach ([
            'build/manifest.json' => '{"app.css": "/build/app.css", "app.js": "/build/app.js"}',
            'build/app.css' => '@font-face { font-family: A; src: url(/build/a.woff2) } body { background: url(bg.png) }',
            'build/app.js' => 'fetch(document.querySelector("[data-index]").dataset.index)',
            'build/a.woff2' => 'WOFF2',
            'build/bg.png' => 'PNG',
        ] as $file => $content) {
            if (!is_dir(\dirname($this->public.'/'.$file))) {
                mkdir(\dirname($this->public.'/'.$file), 0o777, true);
            }
            file_put_contents($this->public.'/'.$file, $content);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->public, $this->out, $this->out.'-2'] as $dir) {
            Folder::remove($dir);
        }
    }

    public function testARedirectionCarriesTheVisitorsFragmentUnderTheBasePath(): void
    {
        (new Exporter())->export($this->site('https://owner.github.io/repo/'), $this->out);

        $stub = (string) file_get_contents($this->out.'/manual/keys/index.html');
        self::assertStringContainsString('<link rel="canonical" href="/repo/blog/hello#keys"><script>', $stub);
        self::assertStringContainsString("t=t.split('#')[0]+'#'+\"keys-\"+h}location.replace(t)", $stub, '/manual/keys#arrows lands on /blog/hello#keys-arrows');
        self::assertStringContainsString('<meta http-equiv="refresh" content="0; url=/repo/blog/hello#keys">', $stub, 'without a script, the target\'s own anchor');
        self::assertStringNotContainsString('<script>', (string) file_get_contents($this->out.'/old/index.html'), 'a redirection without a fragment to carry has no script');
    }

    public function testTheExportIsMovedUnderTheBasePath(): void
    {
        $report = (new Exporter())->export($this->site('https://owner.github.io/repo/'), $this->out);

        self::assertSame([], array_map(strval(...), array_merge($report->errors(), $report->warnings())));
        self::assertSame(['/', '/blog/hello', '/feed.atom', '/fr/blog/bonjour', '/search.json'], array_keys($report->pages), 'the application\'s addresses');

        $home = (string) file_get_contents($this->out.'/index.html');
        foreach ([
            '<link rel="stylesheet" href="/repo/build/app.css">',
            '<script src="/repo/build/app.js" defer></script>',
            '<link rel="canonical" href="https://owner.github.io/repo/">',
            '<a href="/repo/blog/hello">Hello</a>',
            '<img src="/repo/media/photo.jpg" srcset="/repo/media/photo.jpg 1x, /repo/media/photo@2x.jpg 2x" alt="">',
            '<div data-index="/repo/search.json"></div>',
            '<a href="/repo/old">old</a>',
        ] as $expected) {
            self::assertStringContainsString($expected, $home);
        }
        self::assertStringContainsString('<link rel="alternate" hreflang="fr" href="https://owner.github.io/repo/fr/blog/bonjour">', (string) file_get_contents($this->out.'/blog/hello/index.html'));
        self::assertStringContainsString('<a href="/repo/blog/hello">en</a>', (string) file_get_contents($this->out.'/fr/blog/bonjour/index.html'), 'Pages serves it at /repo/fr/blog/bonjour/ too: its relative addresses resolved');
        self::assertStringContainsString('url(/repo/build/a.woff2)', (string) file_get_contents($this->out.'/build/app.css'));
        self::assertStringContainsString('url(bg.png)', (string) file_get_contents($this->out.'/build/app.css'), 'a style sheet\'s relative addresses stay: it is served at its path');
        self::assertSame('PNG', file_get_contents($this->out.'/build/bg.png'));
        self::assertSame('JPEG2', file_get_contents($this->out.'/media/photo@2x.jpg'), 'a srcset candidate followed');
        self::assertStringContainsString('"u":"/repo/blog/hello"', (string) file_get_contents($this->out.'/search.json'));
        self::assertStringContainsString('<link href="https://owner.github.io/repo/blog/hello"/>', (string) file_get_contents($this->out.'/feed.atom'));
        self::assertStringContainsString('<loc>https://owner.github.io/repo/blog/hello</loc>', (string) file_get_contents($this->out.'/sitemap.xml'));
        self::assertStringContainsString('hreflang="fr" href="https://owner.github.io/repo/fr/blog/bonjour"', (string) file_get_contents($this->out.'/sitemap.xml'));
    }

    public function testTheFilesGitHubPagesReads(): void
    {
        $report = (new Exporter())->export($this->site('https://owner.github.io/repo/'), $this->out);

        self::assertSame('', file_get_contents($this->out.'/.nojekyll'));
        self::assertArrayHasKey('.nojekyll', $report->files);
        self::assertFileDoesNotExist($this->out.'/CNAME', 'on owner.github.io');
        self::assertFileDoesNotExist($this->out.'/_redirects', 'Pages reads none');
        self::assertSame(['/repo/manual/keys' => ['to' => '/repo/blog/hello#keys', 'status' => 301], '/repo/old' => ['to' => '/repo/blog/hello', 'status' => 301]], json_decode((string) file_get_contents($this->out.'/redirects.json'), true));
        $stub = (string) file_get_contents($this->out.'/old/index.html');
        self::assertStringContainsString('<meta http-equiv="refresh" content="0; url=/repo/blog/hello">', $stub);
        self::assertStringContainsString('<a href="/repo/blog/hello">/repo/blog/hello</a>', $stub);

        $notFound = (string) file_get_contents($this->out.'/404.html');
        self::assertStringContainsString('<a href="/repo/">Home</a> <a href="/repo/blog/hello">Hello</a>', $notFound, 'served at any depth: its relative addresses resolved');
    }

    public function testACustomDomainIsServedAtTheRootWithACnameFile(): void
    {
        $report = (new Exporter())->export($this->site('https://docs.example.org'), $this->out);

        self::assertFalse($report->hasErrors());
        self::assertSame("docs.example.org\n", file_get_contents($this->out.'/CNAME'));
        self::assertStringContainsString('<a href="/blog/hello">Hello</a>', (string) file_get_contents($this->out.'/index.html'));
        self::assertStringContainsString('<a href="/">Home</a> <a href="/blog/hello">Hello</a>', (string) file_get_contents($this->out.'/404.html'));

        $this->expectException(\InvalidArgumentException::class);
        (new Site('https://docs.example.org/repo'))->githubPages();
    }

    public function testTwoBuildsAreTheSameAndTheCheckPasses(): void
    {
        (new Exporter())->export($this->site('https://owner.github.io/repo/'), $this->out);
        (new Exporter())->export($this->site('https://owner.github.io/repo/'), $this->out.'-2');

        self::assertSame(file_get_contents($this->out.'/omnibridge-static.json'), file_get_contents($this->out.'-2/omnibridge-static.json'));
        $lines = [];
        $cli = new Cli(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        self::assertSame(0, $cli->run(['check', $this->out]), implode("\n", $lines));

        // An address put back outside the base path: the check finds it (and the checksum).
        $home = (string) file_get_contents($this->out.'/index.html');
        file_put_contents($this->out.'/index.html', str_replace('<a href="/repo/blog/hello">', '<a href="/blog/hello">', $home));
        $lines = [];
        self::assertSame(1, $cli->run(['check', $this->out]));
        self::assertContains('outside the base path: index.html: 1 address(es) outside /repo/: /blog/hello', $lines);
    }

    public function testTheCommandLineTargetsGitHubPages(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'site');
        file_put_contents($file, '<?php return (new Omnibridge\Static\Site("https://owner.github.io/repo"))->provide(new Omnibridge\Static\Route\PageList(["/"]))->renderWith(new Omnibridge\Static\Render\CallableRenderer(fn () => Omnibridge\Static\StaticResponse::html("<a href=\"/\">home</a>")));');
        $lines = [];
        $cli = new Cli(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        try {
            self::assertSame(1, $cli->run(['build', $file, '--out='.$this->out, '--target=github-pages', '--strict']), 'no 404 page: a warning');
            self::assertContains('[warning] github-pages 404.html: no page for unknown addresses (Site::notFound()): GitHub Pages shows its own', $lines);
            self::assertFileExists($this->out.'/.nojekyll');
            self::assertStringContainsString('<a href="/repo/">home</a>', (string) file_get_contents($this->out.'/index.html'));
            self::assertSame(2, $cli->run(['build', $file, '--out='.$this->out, '--target=netlify']));
        } finally {
            unlink($file);
        }
    }

    public function testABaseUrlIsAnOriginOrAFolderOfOne(): void
    {
        self::assertSame('', (new Site('https://example.org/'))->basePath());
        self::assertSame('/repo', (new Site('https://owner.github.io/repo/'))->basePath());
        self::assertSame('/a/b', (new Site('https://owner.github.io/a/b'))->basePath());
        self::assertSame('https://owner.github.io', (new Site('https://owner.github.io/repo/'))->origin());
        self::assertNull((new Site('https://owner.github.io/repo/'))->customDomain());
        self::assertSame('docs.example.org', (new Site('https://docs.example.org'))->customDomain());
        foreach (['https://example.org/a/../b', 'https://example.org/a b', 'https://example.org/?q', '/repo'] as $refused) {
            try {
                new Site($refused);
                self::fail($refused.' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function site(string $baseUrl): Site
    {
        $origin = (new Site($baseUrl))->origin();
        $layout = static fn (string $head, string $body): string => '<!DOCTYPE html><html><head><link rel="stylesheet" href="/build/app.css"><script src="/build/app.js" defer></script>'.$head.'</head><body>'.$body.'</body></html>';

        return (new Site($baseUrl))
            ->provide(new PageList([
                '/',
                new StaticPage('/blog/hello', locale: 'en', alternates: ['fr' => '/fr/blog/bonjour']),
                new StaticPage('/fr/blog/bonjour', locale: 'fr', alternates: ['en' => '/blog/hello']),
                '/old',
                '/manual/keys',
                new StaticPage('/search.json', sitemap: false),
                new StaticPage('/feed.atom', sitemap: false),
            ]))
            ->renderWith(new CallableRenderer(static fn (StaticPage $page): StaticResponse => match ($page->path) {
                '/' => StaticResponse::html($layout('<link rel="canonical" href="'.$origin.'/">', '<a href="/blog/hello">Hello</a> <img src="/media/photo.jpg" srcset="/media/photo.jpg 1x, /media/photo@2x.jpg 2x" alt=""> <div data-index="/search.json"></div> <a href="/old">old</a>')),
                '/blog/hello' => StaticResponse::html($layout('<link rel="alternate" hreflang="fr" href="'.$origin.'/fr/blog/bonjour">', '<h1>Hello</h1>')),
                '/fr/blog/bonjour' => StaticResponse::html($layout('', '<h1>Bonjour</h1> <a href="../../blog/hello">en</a>')),
                '/old' => StaticResponse::redirect('/blog/hello'),
                '/manual/keys' => StaticResponse::redirect('/blog/hello#keys', 301, 'keys-'),
                '/search.json' => new StaticResponse(200, '{"entries":[{"u":"/blog/hello","t":"Hello"}]}', 'application/json'),
                '/feed.atom' => new StaticResponse(200, '<feed xmlns="http://www.w3.org/2005/Atom"><entry><link href="'.$origin.'/blog/hello"/></entry></feed>', 'application/atom+xml'),
                '/media/photo.jpg' => new StaticResponse(200, 'JPEG', 'image/jpeg'),
                '/media/photo@2x.jpg' => new StaticResponse(200, 'JPEG2', 'image/jpeg'),
                default => StaticResponse::notFound($layout('', '<a href="./">Home</a> <a href="blog/hello">Hello</a>')),
            }))
            ->publicDir($this->public)
            ->assets($this->public.'/build/manifest.json')
            ->resourceAttributes('data-index')
            ->notFound('/404')
            ->githubPages();
    }
}
