# omnibridge/static

The static host of the omnibridge family: a generic exporter. It enumerates an
application's addresses, renders each at build time with the application's own
code, and writes a folder that any static host or CDN serves without PHP. It is
not a documentation generator: blogs, documentation versions and package
catalogues belong to the application it exports.

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

No framework, no bundler, no extension: the package requires PHP 8.2 and
nothing else (`BareTest` loads every class with no `vendor/` at all). The
adapters for symfony/routing, symfony/http-foundation or a PSR-11 container
name those types only in their signatures, so they need them only when used.

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

`examples/plain/` is a complete one (PHP templates, a manifest, a font a style
sheet refers to, a redirection); the CI builds it, checks it, builds it again
and compares, and keeps the folder as an artifact.

The file may also return a closure that takes what a host offers
(`Cli::HOST_RENDERER`, `HOST_ROUTES`, `HOST_CONTAINER`, `HOST_PUBLIC_DIR`):
the Symfony and Laravel commands pass them, the plain command line passes
nothing and the file boots the application itself.

## The contracts

The master plan's illustrative contracts, as built:

| Type | What it is |
|---|---|
| `StaticPage` | an address to export: its path (no query string), the route and parameters it came from, the action the route runs, its language and its alternates, whether the sitemap lists it, its role (page, page for unknown addresses, resource) |
| `StaticResponse` | what the page answered: status, body, content type, the location of a redirection |
| `StaticRouteProviderInterface::pages(): iterable<StaticPage>` | where the addresses come from |
| `StaticRendererInterface::render(StaticPage): StaticResponse` | how one is rendered |

## How a site declares its static addresses

Explicitly, and nothing is left out without being said so:

- `Route\PageList`: the paths written out (or a closure that gives them).
- `Route\RouteCollectionProvider`: a router's routes (a symfony/routing
  `RouteCollection`: Symfony's router, the one omnibridge/laravel's
  `Routing\ActionRoutes` reads from an application's `#[Route]` actions,
  symfony/routing's attribute loader in plain PHP). A route without a variable
  is one page; a route with variables is exported for the values a source
  gives, by the route's name or, for a localized route (`blog.fr`), by its
  canonical name:

  ```php
  new RouteCollectionProvider($routes,
      parameters: [
          'app_article' => fn () => $db->query('SELECT destination, slug FROM article WHERE published')->fetchAll(),
          'app_index' => fn () => [['destination' => 'japon'], ['destination' => 'usa']],
      ],
      exclude: ['app_*_comments', 'newsletter_*'],   // fnmatch patterns
      actions: ['App\Action\\'],                       // the routes of these actions only
  );
  ```

  Only GET routes are read. A route with variables and no source, a value
  its requirement refuses, a POST route are skipped and reported
  (`skipped-route`). A localized route's languages are each other's
  alternates (`hreflang` in sitemap.xml).
- `Route\SitemapProvider`: the addresses of the application's own sitemap
  (rendered at build time, or a file; a sitemap index is followed).

Routes that must not be static (signed-in pages, posted forms, payments,
per-visitor JSON) are not enumerated, and `Site::dynamic()` names them so that
links to them are not broken links (see below).

## Rendering with the application's own code

- `Render\RequestRenderer`: a GET request of the address handled in this
  process by the host - the route matched, the same action run, the same
  templates rendered, the host's listeners on the way. Symfony's kernel and
  Laravel's both speak symfony/http-foundation:

  ```php
  new RequestRenderer(fn (Request $request) => $kernel->handle($request), 'https://example.org');
  new RequestRenderer(fn (Request $request) => $app->make(Kernel::class)->handle(\Illuminate\Http\Request::createFromBase($request)), 'https://example.org');
  ```

  No cookie, no session: every page is a visitor's. The host's terminate step
  is not run (a host that counts page views there counts none).
- `Render\ActionRenderer`: the route's action called directly from a PSR-11
  container (its route parameters by name, an empty `$query`, its services by
  type), its result turned into a page by the application's own answer (omnibase's
  `Base\Screen\Negotiation`, a template engine). Nothing of the host around
  the action: use it where the action's result is the whole page.
- `Render\CallableRenderer`: any callable.

One process renders every page, as a long-lived worker (FrankenPHP's worker
mode, a queue consumer) would serve them. A service that keeps what one
request left it shows it on the next page; `--verify` (each page rendered
twice) and a page-by-page comparison with the dynamic site find these.

## What the export does

- Pages are written as a static host serves them: `/` as `index.html`,
  `/blog/hello` as `blog/hello/index.html` (nginx: `try_files $uri
  $uri/index.html =404;`), a path ending in `.html` and any other file at its
  path. Two pages for one file is an error (`duplicate-output`), the same page
  twice a warning (`duplicate-page`), a page that is neither HTML nor a path
  with an extension an error (`not-exportable`).
- A redirection is a small page (meta refresh and canonical link) and a line
  of `_redirects` (Netlify, Cloudflare Pages) and `redirects.json`.
- The page for unknown addresses (`Site::notFound()`) is `404.html`.
- Every internal link of the written pages and style sheets is followed: to a
  page or file of the export, to a file of the public folder (copied, symbolic
  links followed), to a file the application serves (rendered and written, a
  style sheet's own links followed in turn), and otherwise reported
  (`broken-link`, with the first page that links to it and how many more).
  Read: `href` of links, `action` of forms, `src`, `srcset`, `poster`, the lazy
  loaders' `data-src` / `data-srcset` / `data-poster`, `<link href>`, `url()` and
  `@import`, and the attributes `Site::resourceAttributes()` adds (a gallery's
  `data-thumb`). Not read: what a script builds at run time. URI templates
  (`/tiles/{z}.png`) are no addresses.
- `Site::dynamic($patterns, $host)`: addresses that stay on the dynamic host.
  Given its origin, their links and forms, and every form posted
  (`method="post"`, which a static host cannot take), are rewritten to it, and
  each is listed (`dynamic` notices); without one, a posted form is a broken
  link.
- `Site::normalise()`: what changes from one request to the next (a CSP nonce,
  a form token, a render time, a random badge) taken out of each page, so that
  two builds give the same folder.
- `sitemap.xml` lists the pages (and their alternates), with no date.
- `omnibridge-static.json`: every file's SHA-256 and size, the pages and the
  file each went to, the redirections, the diagnostics - sorted, no date. A
  build that stops half-way leaves the folder marked, and the next replaces it;
  a folder holding anything else is refused.

## The Symfony and Artisan commands

Thin wrappers over the same `Console\Cli`, each in its host's bridge and
registered only when this package is installed:

- omnibridge/symfony: `bin/console omnibridge:static:build static.php --out=dist`
  (the kernel's renderer, its router's routes, its container, `public/`).
- omnibridge/laravel: `php artisan omnibridge:static:build static.php --out=dist`
  (the HTTP kernel's renderer, the container, `public/`).

## Verified, and not

Verified (2026-10-24):

- The suite: 54 tests (contracts, providers, renderers, links, manifests, the
  exporter, the command line and the plain script without Composer, the
  example), PHP 8.2; PHPStan at max, no baseline entry.
- Les Ringo Stars (Symfony, omnibase 4): its public pages - the shared actions
  of `src/Action`, enumerated from Symfony's router with the values of their
  parameters read from the database - rendered by its own kernel: 243 pages,
  898 files (the pictures the pages show, the Encore build, the map tiles),
  about 280 MB. Compared page by page with the live site, normalised as the
  twin's parity suite does, every difference is one of the handled kinds
  (form tokens emptied, posted forms sent to the dynamic host, the render
  time). Two builds give the same report; `--verify` finds no page rendered
  differently. Served by a plain nginx, headless Chrome on 19 pages: no
  console error and no failed request but the JSON the pages' scripts fetch
  from the dynamic host (below).
- The example site: built, checked, built again, same report (CI).

Not done, or not verified:

- What a page's script fetches at run time (search feeds, "load more", a
  token-bound JSON) is not static: it is not enumerated, and a script that
  calls it from the static site gets a 404. It needs the dynamic host
  (cross-origin, with no session token) or a static JSON the application
  exports at a path with an extension.
- Incremental builds (dependency tracking) are not done: every build is a
  full, clean build.
- Assets are copied as the bundler fingerprinted them; the exporter
  fingerprints nothing itself, and records each file's checksum.
- A site at a sub-path of its origin (`https://example.org/blog/`) is not
  supported: the export is at the root of its origin.
- The Laravel command was not run on a Laravel application's export.
