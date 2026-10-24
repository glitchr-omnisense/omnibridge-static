<?php

namespace Omnibridge\Static;

use Omnibridge\Static\Asset\AssetManifest;
use Omnibridge\Static\Link\Links;
use Omnibridge\Static\Output\OutputPath;
use Omnibridge\Static\Route\SkipReportingInterface;

/**
 * Exports a site to a folder: every page its providers give, rendered by its
 * renderer, written as a file; the files of its asset manifests and the
 * public files it names, copied; every internal link of the pages followed -
 * to a page or file of the export, to a public file (copied), to a file the
 * application serves (rendered and written), to the dynamic host (left, or
 * rewritten to it) - and any other reported as broken; the redirections as
 * small pages, _redirects and redirects.json; 404.html; sitemap.xml; and
 * omnibridge-static.json, the report (files and checksums, pages,
 * redirections, diagnostics).
 *
 *     $report = (new Exporter())->export($site, __DIR__.'/dist');
 *     exit($report->hasErrors() ? 1 : 0);
 *
 * The output is deterministic: pages are rendered in the order of their
 * paths, nothing written carries a date, the report's lists are sorted. With
 * $verify, every page is rendered twice and a page that differs is an error.
 * The folder is emptied first, if it holds an earlier export (its
 * omnibridge-static.json) or nothing.
 */
final class Exporter
{
    public const REPORT = 'omnibridge-static.json';

    private const SKIPPED_SCHEMES = '~^(mailto|tel|sms|javascript|data|blob|about|geo|callto|file):~i';

    private Site $site;
    private string $out;
    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    /** @var array<string, string> file in the export => what wrote it */
    private array $written = [];
    /** @var array<string, string> key of an address => its file in the export */
    private array $served = [];
    /** @var array<string, array{file: string, status: int, route: ?string, locale: ?string}> */
    private array $pages = [];
    /** @var array<string, array{to: string, status: int}> */
    private array $redirects = [];
    /** @var list<array{string, string, string}> file, its address, html|css: the documents whose links are followed */
    private array $documents = [];
    /** @var array<string, true> keys of the pages written */
    private array $pageKeys = [];
    /** @var array<string, array{0: string, 1: int}> key of a broken target => first referrer, count */
    private array $broken = [];
    /** @var array<string, int> */
    private array $dynamicLinks = [];
    /** @var array<string, true> */
    private array $queryIgnored = [];
    /** @var list<StaticPage> */
    private array $sitemapPages = [];

    /** @param (\Closure(string): void)|null $log told of the progress, a line at a time */
    public function __construct(private readonly ?\Closure $log = null)
    {
    }

    public function export(Site $site, string $outDir, bool $verify = false): Report
    {
        $this->site = $site;
        $this->out = rtrim($outDir, '/');
        $this->diagnostics = $this->written = $this->served = $this->pages = $this->redirects = $this->documents = $this->broken = $this->dynamicLinks = $this->queryIgnored = $this->sitemapPages = $this->pageKeys = [];

        $this->prepare();
        $pages = $this->collect();
        $this->copyAssets();

        $count = \count($pages);
        $done = 0;
        foreach ($pages as $page) {
            try {
                $this->page($page, $verify);
            } catch (\RuntimeException $e) {
                $this->diagnostics[] = Diagnostic::error(Diagnostic::RENDER_FAILED, $page->describe(), $e->getMessage());
            }
            if (0 === ++$done % 50 || $done === $count) {
                $this->log(\sprintf('%d/%d pages rendered', $done, $count));
            }
        }

        $this->follow();
        $this->summarise();
        if ($site->writesSitemap()) {
            $this->sitemap();
        }
        $this->writeRedirects();

        $report = new Report($site->baseUrl, $this->checksums(), self::sorted($this->pages), self::sorted($this->redirects), $this->diagnostics);
        file_put_contents($this->out.'/'.self::REPORT, $report->toJson());
        $this->log(\sprintf('%d pages, %d files, %d redirections; %d errors, %d warnings', \count($report->pages), \count($report->files), \count($report->redirects), \count($report->errors()), \count($report->warnings())));

        return $report;
    }

    private function prepare(): void
    {
        if (is_dir($this->out)) {
            $entries = array_diff((array) scandir($this->out), ['.', '..']);
            if ($entries && !is_file($this->out.'/'.self::REPORT)) {
                throw new \RuntimeException(\sprintf('"%s" holds files and no earlier export (%s): give an empty folder.', $this->out, self::REPORT));
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->out, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if (!$entry instanceof \SplFileInfo) {
                    continue;
                }
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        } elseif (!mkdir($this->out, 0o777, true) && !is_dir($this->out)) {
            throw new \RuntimeException(\sprintf('"%s" cannot be made.', $this->out));
        }
        // Marked as an export from the start: a build that stops half-way is replaced by the next one.
        file_put_contents($this->out.'/'.self::REPORT, '{"generator": "omnibridge/static", "complete": false}'."\n");
    }

    /** @return list<StaticPage> the pages to render, one per address, in the order of their paths */
    private function collect(): array
    {
        $pages = [];
        foreach ($this->site->providers() as $provider) {
            foreach ($provider->pages() as $page) {
                $key = OutputPath::key($page->path);
                if (isset($pages[$key])) {
                    $this->diagnostics[] = Diagnostic::warning(Diagnostic::DUPLICATE_PAGE, $page->describe(), \sprintf('given again (first by %s): the first is kept', $pages[$key]->describe()));
                    continue;
                }
                $pages[$key] = $page;
            }
            if ($provider instanceof SkipReportingInterface) {
                foreach ($provider->skipped() as $subject => $why) {
                    $this->diagnostics[] = Diagnostic::warning(Diagnostic::SKIPPED_ROUTE, (string) $subject, $why);
                }
            }
        }
        uksort($pages, strcmp(...));
        $pages = array_values($pages);
        if (null !== $notFound = $this->site->notFoundPage()) {
            $pages[] = $notFound;
        }

        return $pages;
    }

    private function copyAssets(): void
    {
        foreach ($this->site->manifests() as [$manifest, $base]) {
            try {
                $paths = AssetManifest::read($manifest, $base);
            } catch (\RuntimeException|\JsonException $e) {
                $this->diagnostics[] = Diagnostic::error(Diagnostic::MISSING_ASSET, $manifest, $e->getMessage());
                continue;
            }
            foreach ($paths as $path) {
                if (!$this->copyPublic($path)) {
                    $this->diagnostics[] = Diagnostic::error(Diagnostic::MISSING_ASSET, $path, \sprintf('named by %s, not in the public folder', basename($manifest)));
                }
            }
        }

        $public = $this->site->getPublicDir();
        if (!$this->site->copies()) {
            return;
        }
        if (null === $public) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::MISSING_ASSET, implode(', ', $this->site->copies()), 'files to copy, and no public folder (Site::publicDir())');

            return;
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($public) + 1);
            }
        }
        sort($files);
        foreach ($this->site->copies() as $pattern) {
            $found = false;
            foreach ($files as $file) {
                if (fnmatch(ltrim($pattern, '/'), $file)) {
                    $found = true;
                    $this->copyPublic('/'.implode('/', array_map(rawurlencode(...), explode('/', $file))));
                }
            }
            if (!$found) {
                $this->diagnostics[] = Diagnostic::error(Diagnostic::MISSING_ASSET, $pattern, 'no public file matches');
            }
        }
    }

    /** Copies the public file of an address, if there is one. */
    private function copyPublic(string $path): bool
    {
        $public = $this->site->getPublicDir();
        if (null === $public) {
            return false;
        }
        try {
            $file = OutputPath::file($path);
        } catch (\InvalidArgumentException) {
            return false;
        }
        // OutputPath::file() refused any segment that climbs (..): the file is within the public folder,
        // through its symbolic links (an installed bundle's assets, a storage) as the web server follows them.
        $source = $public.'/'.$file;
        if (!is_file($source)) {
            return false;
        }
        $key = OutputPath::key($path);
        if (isset($this->served[$key])) {
            return true;
        }
        $body = file_get_contents($source);
        if (false === $body || !$this->write($file, $body, 'the public file '.$file)) {
            return false;
        }
        $this->served[$key] = $file;
        if (str_ends_with(strtolower($file), '.css')) {
            $this->documents[] = [$file, $path, 'css'];
        }

        return true;
    }

    private function page(StaticPage $page, bool $verify): void
    {
        $response = $this->render($page);
        if (null === $response) {
            return;
        }

        if (StaticPage::NOT_FOUND === $page->role) {
            if (404 !== $response->status) {
                $this->diagnostics[] = Diagnostic::warning(Diagnostic::RENDER_FAILED, $page->describe(), \sprintf('the page for unknown addresses answered %d, not 404', $response->status));
            }
            $this->writePage('404.html', $page, $response);

            return;
        }

        if ($response->isRedirect()) {
            $this->redirect($page, $response);

            return;
        }

        if (!$response->isSuccessful()) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::RENDER_FAILED, $page->describe(), \sprintf('answered %d', $response->status));

            return;
        }

        if ($verify) {
            $again = $this->render($page);
            if (null !== $again && $this->normalised($again, $page)->body !== $this->normalised($response, $page)->body) {
                $this->diagnostics[] = Diagnostic::error(Diagnostic::NOT_DETERMINISTIC, $page->describe(), self::difference($this->normalised($response, $page)->body, $this->normalised($again, $page)->body));
            }
        }

        if ($response->isHtml()) {
            $file = OutputPath::page($page->path);
        } elseif (OutputPath::hasExtension($page->path)) {
            $file = OutputPath::file($page->path);
        } else {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::NOT_EXPORTABLE, $page->describe(), \sprintf('it answers %s, and its path has no extension a static host could serve it by', $response->mediaType() ?? 'no type'));

            return;
        }

        if ($this->writePage($file, $page, $response)) {
            $this->served[OutputPath::key($page->path)] = $file;
            $this->pageKeys[OutputPath::key($page->path)] = true;
            $this->pages[$page->path] = ['file' => $file, 'status' => $response->status, 'route' => $page->route, 'locale' => $page->locale];
            if ($page->sitemap && $response->isHtml() && StaticPage::PAGE === $page->role) {
                $this->sitemapPages[] = $page;
            }
        }
    }

    private function render(StaticPage $page): ?StaticResponse
    {
        try {
            return $this->site->renderer()->render($page);
        } catch (\Throwable $e) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::RENDER_FAILED, $page->describe(), \sprintf('%s: %s', $e::class, $e->getMessage()));

            return null;
        }
    }

    /**
     * A page's body as it is written: normalised and, for HTML with a dynamic host, its links and forms
     * to the dynamic addresses, and its posted forms, sent to the dynamic host ($record: counted for the
     * diagnostics).
     */
    private function normalised(StaticResponse $response, StaticPage $page, bool $record = false): StaticResponse
    {
        if (!$response->isHtml()) {
            return $response;
        }
        $body = $response->body;
        foreach ($this->site->normalisers() as $normaliser) {
            $body = $normaliser($body, $page);
        }
        $host = $this->site->dynamicHost();
        if (null !== $host) {
            $body = Links::rewrite($body, function (string $address, string $kind, array $attributes) use ($page, $host, $record): ?string {
                $target = $this->internal('' === $address ? $page->path : $address, $page->path);
                if (null === $target) {
                    return null;
                }
                $path = (string) parse_url($target, \PHP_URL_PATH);
                $posted = Links::FORM === $kind && 'post' === strtolower(trim($attributes['method'] ?? 'get'));
                if (!$posted && !$this->site->isDynamic($path)) {
                    return null;
                }
                if ($record) {
                    $key = $posted ? 'POST '.$path : $path;
                    $this->dynamicLinks[$key] = ($this->dynamicLinks[$key] ?? 0) + 1;
                }

                return $host.$target;
            });
        }

        return $response->withBody($body);
    }

    private function writePage(string $file, StaticPage $page, StaticResponse $response): bool
    {
        $response = $this->normalised($response, $page, true);
        if (!$this->write($file, $response->body, $page->describe())) {
            return false;
        }
        if ($response->isHtml()) {
            $this->documents[] = [$file, $page->path, 'html'];
        } elseif ($response->isCss()) {
            $this->documents[] = [$file, $page->path, 'css'];
        }

        return true;
    }

    private function redirect(StaticPage $page, StaticResponse $response): void
    {
        $location = (string) $response->location;
        $target = $this->internal($location, $page->path) ?? $location;
        $this->redirects[$page->path] = ['to' => $target, 'status' => $response->status];

        $escaped = htmlspecialchars($target, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $stub = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$escaped.'</title>'
            .'<meta http-equiv="refresh" content="0; url='.$escaped.'"><link rel="canonical" href="'.$escaped.'"><meta name="robots" content="noindex">'
            .'</head><body><a href="'.$escaped.'">'.$escaped.'</a></body></html>'."\n";
        try {
            $file = OutputPath::page($page->path);
        } catch (\InvalidArgumentException $e) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::NOT_EXPORTABLE, $page->describe(), $e->getMessage());

            return;
        }
        if ($this->write($file, $stub, $page->describe())) {
            $this->served[OutputPath::key($page->path)] = $file;
            $this->documents[] = [$file, $page->path, 'html'];
        }
    }

    /** Every link of the documents written, followed until nothing new is found. */
    private function follow(): void
    {
        for ($index = 0; $index < \count($this->documents); ++$index) {
            [$file, $address, $kind] = $this->documents[$index];
            $body = (string) file_get_contents($this->out.'/'.$file);
            try {
                $links = 'css' === $kind ? Links::css($body) : Links::html($body, $this->site->getResourceAttributes());
            } catch (\RuntimeException $e) {
                $this->diagnostics[] = Diagnostic::error(Diagnostic::BROKEN_LINK, $address, 'its links could not be read: '.$e->getMessage());
                continue;
            }
            foreach ($links as [$linkKind, $reference]) {
                $this->link($linkKind, $reference, $address);
            }
        }
    }

    private function link(string $kind, string $reference, string $from): void
    {
        $target = $this->internal('' === $reference && Links::RESOURCE !== $kind ? $from : $reference, $from);
        if (null === $target) {
            return;
        }
        $path = (string) parse_url($target, \PHP_URL_PATH);
        $query = parse_url($target, \PHP_URL_QUERY);
        $key = OutputPath::key($path);

        if ($this->site->isDynamic($path)) {
            // Left to the dynamic host, which the site names none of (with one, the link was rewritten).
            $this->dynamicLinks[$path] = ($this->dynamicLinks[$path] ?? 0) + 1;

            return;
        }
        if (Links::POST === $kind) {
            $this->broken($path, $from, 'a form posted to it, and a static host takes no post: Site::dynamic() names a dynamic host to send it to');

            return;
        }
        if (isset($this->broken[$key])) {
            ++$this->broken[$key][1];

            return;
        }
        if (isset($this->served[$key])) {
            if (Links::RESOURCE !== $kind && \is_string($query) && '' !== $query && isset($this->pageKeys[$key])) {
                $this->queryIgnored[$target] = true;
            }

            return;
        }
        if ($this->copyPublic($path)) {
            return;
        }
        if (!$this->site->followsResources()) {
            $this->broken($path, $from, 'not in the export');

            return;
        }
        $this->resource($path, $from);
    }

    /** A file the pages refer to that the application serves, rendered and written as it is. */
    private function resource(string $path, string $from, int $hops = 0): void
    {
        $key = OutputPath::key($path);
        // Asked once, whatever comes of it: a later link finds it served, or broken.
        $this->served[$key] = '';
        try {
            $page = new StaticPage($path, sitemap: false, role: StaticPage::RESOURCE);
        } catch (\InvalidArgumentException $e) {
            $this->broken($path, $from, $e->getMessage());

            return;
        }
        $response = $this->render($page);
        if (null === $response) {
            $this->broken($path, $from, 'its rendering failed');

            return;
        }
        if ($response->isRedirect() && $hops < 5) {
            $target = $this->internal((string) $response->location, $path);
            if (null !== $target) {
                $this->redirects[$path] = ['to' => $target, 'status' => $response->status];
                $targetPath = (string) parse_url($target, \PHP_URL_PATH);
                if (!isset($this->served[OutputPath::key($targetPath)]) && !$this->copyPublic($targetPath)) {
                    $this->resource($targetPath, $from, $hops + 1);
                }

                return;
            }
        }
        if (!$response->isSuccessful()) {
            $this->broken($path, $from, \sprintf('answered %d', $response->status));

            return;
        }
        if ($response->isHtml()) {
            $this->broken($path, $from, 'a page no provider lists');

            return;
        }
        try {
            $file = OutputPath::file($path);
        } catch (\InvalidArgumentException $e) {
            $this->broken($path, $from, $e->getMessage());

            return;
        }
        if ($this->write($file, $response->body, 'the resource '.$path)) {
            $this->served[$key] = $file;
            if ($response->isCss()) {
                $this->documents[] = [$file, $path, 'css'];
            }
        }
    }

    private function broken(string $path, string $from, string $why): void
    {
        $key = OutputPath::key($path);
        if (isset($this->broken[$key])) {
            ++$this->broken[$key][1];

            return;
        }
        $this->broken[$key] = [$from.' ('.$why.')', 1];
        unset($this->served[$key]);
    }

    /** The diagnostics of the links, one per address. */
    private function summarise(): void
    {
        ksort($this->broken);
        foreach ($this->broken as $target => [$from, $count]) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::BROKEN_LINK, $target, \sprintf('linked from %s%s', $from, $count > 1 ? \sprintf(' and %d more', $count - 1) : ''));
        }
        ksort($this->dynamicLinks);
        foreach ($this->dynamicLinks as $path => $count) {
            $this->diagnostics[] = Diagnostic::notice(Diagnostic::DYNAMIC, $path, \sprintf('%d %s the dynamic host%s', $count, str_starts_with($path, 'POST ') ? 'form(s) posted to' : 'link(s) left to', null !== $this->site->dynamicHost() ? ' ('.$this->site->dynamicHost().')' : ''));
        }
        ksort($this->queryIgnored);
        foreach (array_keys($this->queryIgnored) as $target) {
            $this->diagnostics[] = Diagnostic::warning(Diagnostic::QUERY_IGNORED, $target, 'a static host answers the page of this path whatever its query');
        }
    }

    /**
     * The path and query an internal reference names, resolved against the document's address; null
     * for one of another origin, a fragment alone, or a scheme that is no address (mailto:...).
     */
    private function internal(string $reference, string $from): ?string
    {
        $reference = trim($reference);
        // A URI template ("/tiles/{z}/{x}.png") a script fills is no address.
        if ('' === $reference || '#' === $reference[0] || str_contains(rawurldecode($reference), '{') || preg_match(self::SKIPPED_SCHEMES, $reference)) {
            return null;
        }
        if (false !== $hash = strpos($reference, '#')) {
            $reference = substr($reference, 0, $hash);
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*:)?//~i', $reference)) {
            $parts = parse_url(str_starts_with($reference, '//') ? 'https:'.$reference : $reference);
            if (false === $parts || !isset($parts['host'])) {
                return null;
            }
            $origin = (array) parse_url($this->site->origin());
            $port = $parts['port'] ?? null;
            $samePort = $port === ($origin['port'] ?? null) || (null === $port && null === ($origin['port'] ?? null));
            if (strtolower($parts['host']) !== ($origin['host'] ?? '') || !$samePort || (str_starts_with($reference, '//') ? false : strtolower($parts['scheme'] ?? '') !== ($origin['scheme'] ?? ''))) {
                return null;
            }
            $reference = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        } elseif (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) {
            return null;
        }

        return OutputPath::resolve($from, $reference);
    }

    private function sitemap(): void
    {
        if (!$this->sitemapPages) {
            return;
        }
        $base = rtrim($this->site->baseUrl, '/');
        usort($this->sitemapPages, static fn (StaticPage $a, StaticPage $b) => strcmp($a->path, $b->path));
        $alternates = false;
        $entries = '';
        foreach ($this->sitemapPages as $page) {
            $entries .= '  <url>'."\n".'    <loc>'.htmlspecialchars($base.$page->path, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</loc>'."\n";
            if ($page->alternates) {
                $alternates = true;
                $links = $page->alternates + (null !== $page->locale ? [$page->locale => $page->path] : []);
                ksort($links);
                foreach ($links as $locale => $path) {
                    $entries .= '    <xhtml:link rel="alternate" hreflang="'.htmlspecialchars((string) $locale, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'" href="'.htmlspecialchars($base.$path, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'"/>'."\n";
                }
            }
            $entries .= '  </url>'."\n";
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'.($alternates ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '').'>'."\n"
            .$entries.'</urlset>'."\n";
        if ($this->write('sitemap.xml', $xml, 'the sitemap')) {
            $this->served['/sitemap.xml'] = 'sitemap.xml';
        }
    }

    private function writeRedirects(): void
    {
        if (!$this->redirects) {
            return;
        }
        $redirects = self::sorted($this->redirects);
        $lines = '';
        foreach ($redirects as $from => $redirect) {
            $lines .= $from.' '.$redirect['to'].' '.$redirect['status']."\n";
        }
        // Netlify's and Cloudflare Pages' format; redirects.json for any other host's configuration.
        $this->write('_redirects', $lines, 'the redirections');
        $this->write('redirects.json', json_encode($redirects, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n", 'the redirections');
    }

    private function write(string $file, string $body, string $owner): bool
    {
        if (isset($this->written[$file])) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::DUPLICATE_OUTPUT, $file, \sprintf('%s and %s are both written to it: the first is kept', $this->written[$file], $owner));

            return false;
        }
        $path = $this->out.'/'.$file;
        $directory = \dirname($path);
        if (!is_dir($directory) && (file_exists($directory) || !@mkdir($directory, 0o777, true))) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::DUPLICATE_OUTPUT, $file, \sprintf('%s: its folder is a file of the export already', $owner));

            return false;
        }
        if (is_dir($path)) {
            $this->diagnostics[] = Diagnostic::error(Diagnostic::DUPLICATE_OUTPUT, $file, \sprintf('%s: a folder of the export has this name already', $owner));

            return false;
        }
        file_put_contents($path, $body);
        $this->written[$file] = $owner;

        return true;
    }

    /** @return array<string, array{sha256: string, size: int}> */
    private function checksums(): array
    {
        $files = [];
        foreach (array_keys($this->written) as $file) {
            $path = $this->out.'/'.$file;
            $files[$file] = ['sha256' => (string) hash_file('sha256', $path), 'size' => (int) filesize($path)];
        }
        ksort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @template T
     *
     * @param array<string, T> $values
     *
     * @return array<string, T>
     */
    private static function sorted(array $values): array
    {
        ksort($values, \SORT_STRING);

        return $values;
    }

    /** Where two renderings of a page part: the first line that differs, both ways. */
    private static function difference(string $first, string $second): string
    {
        $a = explode("\n", $first);
        $b = explode("\n", $second);
        foreach ($a as $line => $text) {
            if (($b[$line] ?? null) !== $text) {
                return \sprintf('rendered twice, line %d differs: "%s" then "%s"', $line + 1, substr(trim($text), 0, 160), substr(trim($b[$line] ?? ''), 0, 160));
            }
        }

        return \sprintf('rendered twice, the second is longer (%d lines, then %d)', \count($a), \count($b));
    }

    private function log(string $line): void
    {
        if (null !== $this->log) {
            ($this->log)($line);
        }
    }
}
