---
title: The export
order: 30
---

# The export

`Omnibridge\Static\Exporter::export(Site $site, string $outDir, bool $verify = false): Report` - what
the command line's `build` runs. In order: the folder prepared (created, or emptied when it holds an
earlier export; marked as an export from the start, so a build that stops half-way is replaced by the
next one; a folder holding anything else is refused with a `RuntimeException`), the pages collected, the
manifests' files and the copies written from the public folder, each page rendered and written, the
links followed, the diagnostics summarised, `sitemap.xml`, the redirections, the base path applied
([GitHub Pages](github-pages.md)), `omnibridge-static.json`. A progress line every 50 pages, and a last
line with the counts.

## The files

Pages are written as a static host serves them: `/` as `index.html`, `/blog/hello` as
`blog/hello/index.html` (nginx: `try_files $uri $uri/index.html =404;`), a path ending in `.html` and
any other file at its path. Paths are percent-decoded (`/caf%C3%A9` is `café/index.html`). Two pages
for one file is an error (`duplicate-output`), the same page twice a warning (`duplicate-page`), a page
that is neither HTML nor a path with an extension an error (`not-exportable`) - unless it answers
`StaticResponse::bytes()`: a static host serves a path without extension as
`application/octet-stream`, which a reader such as an npm or Unity registry client takes
(omnisense/omnihub's static hub writes `/<package>` and `/-/v1/search` so).

| Answer | Written |
|---|---|
| 2xx HTML | `OutputPath::page()` |
| 2xx other type, path with an extension (1 to 8 letters or digits), or `bytes()` | `OutputPath::file()`, at its path |
| 3xx with a `Location` | a redirection page, below |
| the page for unknown addresses | `404.html` (a warning when it does not answer 404) |
| any other status | `render-failed` error, nothing written |

The public files: each file a manifest names (`Site::assets()`, read by `Asset\AssetManifest`:
Vite's `manifest.json` with `file`, `css`, `assets` relative to its `base`; Webpack's or Encore's
`manifest.json`, logical name => public path; Encore's `entrypoints.json`), and each pattern of
`Site::copy()` (relative to the public folder, `fnmatch`), copied as they are - a missing one is a
`missing-asset` error. Addresses on another host (a CDN) are not copied. The exporter fingerprints
nothing: assets are copied as the bundler fingerprinted them, and each file's checksum recorded.

## Redirections

A redirection is a small page (meta refresh and canonical link) and a line of `_redirects` (Netlify,
Cloudflare Pages; not for GitHub Pages, which reads none) and `redirects.json` (`{from: {to, status}}`).
A target keeps its own anchor (`/docs/flowjs#sticky`). A static host never sees the visitor's own
`#fragment`: `StaticResponse::redirect($to, 301, fragment: 'sticky-')` - or, through `RequestRenderer`,
a response header `X-Static-Fragment: sticky-` beside its `Location` - carries it over, prefixed, with
a line of script before the refresh: `/docs/flowjs/sticky#keys` lands on `/docs/flowjs#sticky-keys` (a
page merged into another, its anchors prefixed); without script, the target's own anchor. `fragment:
''` carries the fragment unprefixed; `null` (the default) drops it, as a meta refresh does. The page is
`noindex`; its target is followed like any link.

## The page for unknown addresses

`Site::notFound('/404')` renders an address the application does not serve and writes the answer to
`404.html`, its status expected to be 404; it is not listed in the sitemap. Its relative addresses are
resolved against the address it was rendered at, since a host serves it at any depth.

## Links followed

Every internal link of the written pages and style sheets is followed: to a page or file of the
export, to a file of the public folder (copied, symbolic links followed), to a file the application
serves (rendered and written, a style sheet's own links followed in turn), and otherwise reported
(`broken-link`, with the first page that links to it and how many more). Read: `href` of links,
`action` of forms, `src`, `srcset`, `poster`, the lazy loaders' `data-src` / `data-srcset` /
`data-poster`, `<link href>`, an SVG's `<use href>` and `<image href>`, a `<meta http-equiv="refresh">`,
`url()` and `@import`, and the attributes `Site::resourceAttributes()` adds (a gallery's `data-thumb`).
Not read: what a script builds at run time. URI templates (`/tiles/{z}.png`) are no addresses. Skipped:
other origins, a fragment alone, `mailto:`, `tel:`, `sms:`, `javascript:`, `data:`, `blob:`, `about:`,
`geo:`, `callto:`, `file:`. A link with a query string to an exported page is a `query-ignored` warning:
a static host answers the page whatever the query. `Site::followResources(false)` stops rendering the
files the application serves: a link to one is then a broken link.

## The dynamic host

`Site::dynamic($patterns, $host)`: addresses that stay on the dynamic host (`fnmatch` patterns of
paths, `*` crossing slashes). Given its origin, their links and forms, and every form posted
(`method="post"`, which a static host cannot take), are rewritten to it, and each is listed (`dynamic`
notices); without one, a link to them is no broken link, and a posted form is.

## Normalisers

`Site::normalise(fn (string $html, StaticPage $page): string => ...)`: what changes from one request to
the next (a CSP nonce, a form token, a render time, a random badge) taken out of each HTML page before
it is written (and before `--verify` compares), so that two builds give the same folder.

```php
->normalise(fn (string $html) => preg_replace('~name="_token" value="[^"]*"~', 'name="_token" value=""', $html))
```

## sitemap.xml and omnibridge-static.json

`sitemap.xml` lists the HTML pages of role page whose `sitemap` is true, sorted, at the base URL, with
their alternates (`xhtml:link hreflang`), with no date. `Site::sitemap(false)` writes none.

`omnibridge-static.json` (`Exporter::REPORT`): `generator`, `baseUrl`, `pages` (path => file, status,
route, locale), `redirects`, `files` (every file's `sha256` and `size`), `diagnostics` - sorted, no date.
Two builds of the same application give the same file; `check` compares a folder with it.

## A run

A router's routes, the host's handling as a closure, a dynamic host, a fragment carried over (the
package's image, 2026-10-27):

```php
<?php

require 'vendor/autoload.php';

use Omnibridge\Static\Exporter;
use Omnibridge\Static\Render\RequestRenderer;
use Omnibridge\Static\Route\RouteCollectionProvider;
use Omnibridge\Static\Site;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();
$routes->add('home', new Route('/'));
$routes->add('article', new Route('/articles/{slug}', requirements: ['slug' => '[a-z-]+']));
$routes->add('docs_sticky', new Route('/docs/flowjs/sticky'));
$routes->add('comment', (new Route('/articles/{slug}/comment'))->setMethods(['POST']));
$routes->add('feed', new Route('/feed/{page}'));

// A host's request handling, by hand: what a kernel's handle() would answer.
$handle = static function (Request $request): Response {
    return match ($request->getPathInfo()) {
        '/' => new Response('<html><body><a href="/articles/le-fuji">Le Fuji</a> <a href="/login">Sign in</a> <a href="/missing">?</a></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        '/articles/le-fuji' => new Response('<html><body><form method="post" action="/articles/le-fuji/comment"></form><img src="/img/fuji.jpg"></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        '/img/fuji.jpg' => new Response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
        '/docs/flowjs/sticky' => new Response('', 301, ['Location' => '/docs/flowjs#sticky', 'X-Static-Fragment' => 'sticky-']),
        default => new Response('<html><body>Not found</body></html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']),
    };
};

$site = (new Site('https://example.org'))
    ->provide(new RouteCollectionProvider($routes, parameters: ['article' => [['slug' => 'le-fuji'], ['slug' => 'Not Valid']]]))
    ->renderWith(new RequestRenderer($handle, 'https://example.org'))
    ->dynamic(['/login', '/articles/*/comment'], 'https://app.example.org')
    ->notFound('/404');

$report = (new Exporter())->export($site, 'dist');
foreach ($report->diagnostics as $diagnostic) {
    echo $diagnostic, "\n";
}
echo json_encode($report->redirects, JSON_UNESCAPED_SLASHES), "\n";
echo implode(' ', array_keys($report->files)), "\n";
echo file_get_contents('dist/docs/flowjs/sticky/index.html');
echo file_get_contents('dist/articles/le-fuji/index.html'), "\n";
```

```text
[warning] skipped-route article {"slug":"Not Valid"}: not generated: Parameter "slug" for route "article" must match "[a-z-]+" ("Not Valid" given) to generate a corresponding URL.
[warning] skipped-route comment: it answers POST, not GET
[warning] skipped-route feed: its path /feed/{page} has variables (page) and no source gives their values
[error] broken-link /docs/flowjs: linked from /docs/flowjs/sticky (answered 404) and 2 more
[error] broken-link /missing: linked from / (answered 404)
[notice] dynamic /login: 1 link(s) left to the dynamic host (https://app.example.org)
[notice] dynamic POST /articles/le-fuji/comment: 1 form(s) posted to the dynamic host (https://app.example.org)
{"/docs/flowjs/sticky":{"to":"/docs/flowjs#sticky","status":301}}
404.html _redirects articles/le-fuji/index.html docs/flowjs/sticky/index.html img/fuji.jpg index.html redirects.json sitemap.xml
<!DOCTYPE html><html><head><meta charset="utf-8"><title>/docs/flowjs#sticky</title><link rel="canonical" href="/docs/flowjs#sticky"><script>(function(){var t=document.querySelector('link[rel="canonical"]').getAttribute('href'),h=location.hash.slice(1);if(h){t=t.split('#')[0]+'#'+"sticky-"+h}location.replace(t)})()</script><meta http-equiv="refresh" content="0; url=/docs/flowjs#sticky"><meta name="robots" content="noindex"></head><body><a href="/docs/flowjs#sticky">/docs/flowjs#sticky</a></body></html>
<html><body><form method="post" action="https://app.example.org/articles/le-fuji/comment"></form><img src="/img/fuji.jpg"></body></html>
```

- `/img/fuji.jpg` is in no public folder: the application serves it, so it was rendered and written.
- `/docs/flowjs` is no page of this export: the redirection's three links to it (canonical, refresh,
  body) make one `broken-link` error; so does `/missing`. The build exits 1.
- `/login` stays on the dynamic host; the posted form is rewritten to it.
