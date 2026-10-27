# omnibridge/static

The static host of the omnibridge family: a generic exporter. It enumerates an application's
addresses, renders each at build time with the application's own code, and writes a folder that any
static host or CDN serves without PHP. It is not a documentation generator: blogs, documentation
versions and package catalogues belong to the application it exports.

```text
explicit addresses (lists, routes + parameter values, a sitemap)
        |
build-time rendering by the application's own code
        |
HTML + files the pages need + redirections + 404.html + sitemap.xml
        |
a deterministic folder with omnibridge-static.json (checksums, diagnostics)
        |
static host / CDN
```

No framework, no bundler, no extension: the package requires PHP 8.2 and nothing else (`BareTest`
loads every class with no `vendor/` at all). The adapters for symfony/routing,
symfony/http-foundation or a PSR-11 container name those types only in their signatures, so they need
them only when used.

```sh
composer require omnibridge/static:1.x-dev
```

## Plain PHP first

A site is declared in one PHP file that returns an `Omnibridge\Static\Site`:

```php
use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;

return (new Site('https://example.org'))
    ->provide(new PageList(['/', '/posts/hello', '/old-blog']))
    ->renderWith(new CallableRenderer(fn (StaticPage $page) => match ($page->path) {
        '/' => render('home.php'),
        '/posts/hello' => render('post.php', ['slug' => 'hello']),
        '/old-blog' => StaticResponse::redirect('/'),
        default => StaticResponse::notFound(render('not-found.php')),
    }))
    ->publicDir(__DIR__.'/public')
    ->assets(__DIR__.'/public/build/manifest.json')   // Vite, Webpack or Encore
    ->copy('robots.txt', 'favicon.ico')
    ->notFound('/404');
```

```sh
vendor/bin/omnibridge-static build site.php --out=dist     # exit 1 on an error (--strict: on a warning too)
vendor/bin/omnibridge-static check dist                    # the files against omnibridge-static.json
```

`examples/plain/` is a complete one (PHP templates, a manifest, a font a style sheet refers to, a
redirection); the CI builds it, checks it, builds it again and compares, and keeps the folder as an
artifact. [Installation](installation.md) runs it.

The file may also return a closure that takes what a host offers (`Cli::HOST_RENDERER`,
`HOST_ROUTES`, `HOST_CONTAINER`, `HOST_PUBLIC_DIR`): the Symfony and Laravel commands pass them, the
plain command line passes nothing and the file boots the application itself ([Hosts](hosts.md)).

## The contracts

The master plan's illustrative contracts, as built:

| Type | What it is |
|---|---|
| `StaticPage` | an address to export: its path (no query string), the route and parameters it came from, the action the route runs, its language and its alternates, whether the sitemap lists it, its role (page, page for unknown addresses, resource) |
| `StaticResponse` | what the page answered: status, body, content type, the location of a redirection |
| `StaticRouteProviderInterface::pages(): iterable<StaticPage>` | where the addresses come from |
| `StaticRendererInterface::render(StaticPage): StaticResponse` | how one is rendered |

## How this manual is organised

- [Installation](installation.md): requirements, the command line, the example built and checked.
- [Hosts](hosts.md): the site file's closure, the Symfony and Artisan commands.
- [Addresses](addresses.md): `PageList`, `RouteCollectionProvider`, `SitemapProvider`, writing one.
- [Rendering](rendering.md): `RequestRenderer`, `ActionRenderer`, `CallableRenderer`, one process.
- [The export](export.md): files, redirections (and `X-Static-Fragment`), 404, links followed, the
  dynamic host, normalisers, `sitemap.xml`, `omnibridge-static.json`.
- [GitHub Pages](github-pages.md): the base path, `.nojekyll`, `CNAME`, the check, the workflow.
- [Reference](reference.md): `Site`, `StaticPage`, `StaticResponse`, `Report` - every method and field.
- [Errors](errors.md): every diagnostic code, the exceptions, the exit codes.
- [Testing](testing.md), [Verified, and not](verified.md).

## Licence

MIT.
