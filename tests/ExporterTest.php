<?php

namespace Omnibridge\Static\Tests;

use Omnibridge\Static\Diagnostic;
use Omnibridge\Static\Exporter;
use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Report;
use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;
use Omnibridge\Static\Tests\Fixtures\Folder;
use Omnibridge\Static\Tests\Fixtures\PlainApp;
use PHPUnit\Framework\TestCase;

final class ExporterTest extends TestCase
{
    private string $public;
    private string $out;

    protected function setUp(): void
    {
        $this->public = PlainApp::publicDir();
        $this->out = sys_get_temp_dir().'/omnibridge-static-out-'.getmypid();
    }

    protected function tearDown(): void
    {
        foreach ([$this->public, $this->out, $this->out.'-2'] as $dir) {
            Folder::remove($dir);
        }
    }

    public function testThePagesAreWrittenAsAStaticHostServesThem(): void
    {
        $report = (new Exporter())->export(PlainApp::site($this->public), $this->out);

        self::assertSame(['/', '/blog/hello', '/en/blog/hello'], array_keys($report->pages));
        self::assertStringContainsString('<h1>Bonjour</h1>', (string) file_get_contents($this->out.'/blog/hello/index.html'));
        self::assertFileExists($this->out.'/index.html');
        self::assertFileExists($this->out.'/en/blog/hello/index.html');
        self::assertStringContainsString('<h1>Not found</h1>', (string) file_get_contents($this->out.'/404.html'));
    }

    public function testTheFilesThePagesNeedAreCopiedOrRendered(): void
    {
        (new Exporter())->export(PlainApp::site($this->public), $this->out);

        self::assertSame(PlainApp::PUBLIC['build/app.3f2a.css'], file_get_contents($this->out.'/build/app.3f2a.css'), 'named by the manifest');
        self::assertSame('WOFF2', file_get_contents($this->out.'/build/fonts/a.woff2'), 'named by the style sheet, from the public folder');
        self::assertSame('ICO', file_get_contents($this->out.'/favicon.ico'), 'copied by pattern');
        self::assertSame('JPEG', file_get_contents($this->out.'/media/photo.jpg'), 'served by the application, rendered');
        self::assertFileDoesNotExist($this->out.'/build/manifest.json', 'the manifest itself is the application\'s, not the static site\'s');
    }

    public function testARedirectionIsASmallPageAndALine(): void
    {
        $report = (new Exporter())->export(PlainApp::site($this->public), $this->out);

        self::assertSame(['/old' => ['to' => '/blog/hello', 'status' => 301]], $report->redirects);
        self::assertSame("/old /blog/hello 301\n", file_get_contents($this->out.'/_redirects'));
        self::assertStringContainsString('<meta http-equiv="refresh" content="0; url=/blog/hello">', (string) file_get_contents($this->out.'/old/index.html'));
        self::assertJson((string) file_get_contents($this->out.'/redirects.json'));
    }

    public function testTheSitemapListsThePagesAndTheirLanguages(): void
    {
        (new Exporter())->export(PlainApp::site($this->public), $this->out);

        $sitemap = (string) file_get_contents($this->out.'/sitemap.xml');
        self::assertStringContainsString('<loc>https://example.org/</loc>', $sitemap);
        self::assertStringContainsString('<loc>https://example.org/blog/hello</loc>', $sitemap);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="https://example.org/en/blog/hello"/>', $sitemap);
        self::assertStringNotContainsString('/old', $sitemap, 'a redirection is no page');
        self::assertStringNotContainsString('/404', $sitemap);
    }

    public function testEveryLinkIsCheckedAndTheDynamicOnesAreSentToTheDynamicHost(): void
    {
        $report = (new Exporter())->export(PlainApp::site($this->public), $this->out);

        self::assertSame(['/missing'], array_map(static fn (Diagnostic $diagnostic) => $diagnostic->subject, $report->errors()), 'the one internal link to nothing; the other origin, mailto: are not checked');
        self::assertStringContainsString('answered 404', $report->errors()[0]->message);
        $home = (string) file_get_contents($this->out.'/index.html');
        self::assertStringContainsString('href="https://app.example.org/login"', $home);
        self::assertStringContainsString('action="https://app.example.org/newsletter"', (string) file_get_contents($this->out.'/blog/hello/index.html'));
        $english = (string) file_get_contents($this->out.'/en/blog/hello/index.html');
        self::assertStringContainsString('<form method="post" class="comment" action="https://app.example.org/en/blog/hello">', $english, 'a form posted to its own page goes to the dynamic host');
        self::assertStringContainsString('<form method="get" action="/en/blog/hello">', $english, 'a form that gets a static page stays');
        self::assertSame(['/login', 'POST /en/blog/hello', 'POST /newsletter'], self::subjects($report, Diagnostic::DYNAMIC));
        self::assertSame(['/blog/hello?page=2'], self::subjects($report, Diagnostic::QUERY_IGNORED));
    }

    public function testTheReportIsWrittenAndTwoBuildsAreTheSame(): void
    {
        $first = (new Exporter())->export(PlainApp::site($this->public), $this->out);
        $second = (new Exporter())->export(PlainApp::site($this->public), $this->out.'-2');

        self::assertSame($first->toJson(), file_get_contents($this->out.'/'.Exporter::REPORT));
        self::assertSame(file_get_contents($this->out.'/'.Exporter::REPORT), file_get_contents($this->out.'-2/'.Exporter::REPORT));
        self::assertSame(hash_file('sha256', $this->out.'/index.html'), $first->files['index.html']['sha256']);
        self::assertArrayNotHasKey(Exporter::REPORT, $first->files);
        self::assertSame($second->files, $first->files);
    }

    public function testAnEarlierExportIsReplacedAnyOtherFolderIsRefused(): void
    {
        (new Exporter())->export(PlainApp::site($this->public), $this->out);
        file_put_contents($this->out.'/stale.html', 'stale');
        (new Exporter())->export(PlainApp::site($this->public), $this->out);
        self::assertFileDoesNotExist($this->out.'/stale.html');

        unlink($this->out.'/'.Exporter::REPORT);
        $this->expectException(\RuntimeException::class);
        (new Exporter())->export(PlainApp::site($this->public), $this->out);
    }

    public function testAPageRenderedDifferentlyTwiceIsAnErrorUnlessNormalised(): void
    {
        $count = 0;
        $site = fn (bool $normalised): Site => (new Site('https://example.org'))
            ->provide(new PageList(['/']))
            ->renderWith(new CallableRenderer(static function () use (&$count): string {
                return '<p data-token="'.++$count.'">Hello</p>';
            }))
            ->normalise(static fn (string $html): string => $normalised ? (string) preg_replace('~ data-token="\d+"~', '', $html) : $html);

        $report = (new Exporter())->export($site(false), $this->out, verify: true);
        self::assertSame(['/'], self::subjects($report, Diagnostic::NOT_DETERMINISTIC));

        $report = (new Exporter())->export($site(true), $this->out, verify: true);
        self::assertSame([], $report->errors());
        self::assertSame('<p>Hello</p>', file_get_contents($this->out.'/index.html'));
    }

    public function testWhatCannotBeAStaticFileIsAnError(): void
    {
        $site = (new Site('https://example.org'))
            ->provide(new PageList(['/a', '/a/index.html', '/api/items', '/registry/com.example.thing', '/broken', '/a', '/form']))
            ->renderWith(new CallableRenderer(static fn (StaticPage $page) => match ($page->path) {
                '/api/items' => StaticResponse::json('[]'),
                '/registry/com.example.thing' => StaticResponse::bytes('{"name":"com.example.thing"}'),
                '/form' => '<form method="post"><button>Send</button></form>',
                '/broken' => new StaticResponse(500, 'oops', 'text/html'),
                default => '<p>A</p>',
            }));

        $report = (new Exporter())->export($site, $this->out);

        self::assertSame(['a/index.html'], self::subjects($report, Diagnostic::DUPLICATE_OUTPUT), 'two pages, one file');
        self::assertSame(['/a'], self::subjects($report, Diagnostic::DUPLICATE_PAGE), 'the same page twice: a warning');
        self::assertSame(['/api/items'], self::subjects($report, Diagnostic::NOT_EXPORTABLE), 'JSON at a path without extension');
        self::assertSame('{"name":"com.example.thing"}', file_get_contents($this->out.'/registry/com.example.thing'), 'bytes at a path without extension: exported, served as they say');
        self::assertSame(['/broken'], self::subjects($report, Diagnostic::RENDER_FAILED));
        self::assertSame(['/form'], self::subjects($report, Diagnostic::BROKEN_LINK), 'a form posted, and no dynamic host');
        self::assertStringContainsString('no post', $report->errors()[\count($report->errors()) - 1]->message);
    }

    /** @return list<string> */
    private static function subjects(Report $report, string $code): array
    {
        return array_values(array_map(static fn (Diagnostic $diagnostic) => $diagnostic->subject, array_filter($report->diagnostics, static fn (Diagnostic $diagnostic) => $diagnostic->code === $code)));
    }
}
