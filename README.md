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
  of `_redirects` (Netlify, Cloudflare Pages; not for GitHub Pages, which
  reads none) and `redirects.json`. A target keeps its own anchor
  (`/docs/flowjs#sticky`). A static host never sees the visitor's own
  `#fragment`: `StaticResponse::redirect($to, 301, fragment: 'sticky-')` - or,
  through `RequestRenderer`, a response header `X-Static-Fragment: sticky-`
  beside its `Location` - carries it over, prefixed, with a line of script
  before the refresh: `/docs/flowjs/sticky#keys` lands on
  `/docs/flowjs#sticky-keys` (a page merged into another, its anchors
  prefixed); without script, the target's own anchor.
- The page for unknown addresses (`Site::notFound()`) is `404.html`.
- Every internal link of the written pages and style sheets is followed: to a
  page or file of the export, to a file of the public folder (copied, symbolic
  links followed), to a file the application serves (rendered and written, a
  style sheet's own links followed in turn), and otherwise reported
  (`broken-link`, with the first page that links to it and how many more).
  Read: `href` of links, `action` of forms, `src`, `srcset`, `poster`, the lazy
  loaders' `data-src` / `data-srcset` / `data-poster`, `<link href>`, an SVG's
  `<use href>` and `<image href>`, a `<meta http-equiv="refresh">`, `url()` and
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

## Deploying to GitHub Pages

GitHub Pages serves a project site under a folder of its owner's origin
(`https://<owner>.github.io/<repository>/`), a user or organisation site and
a custom domain at the root. Give the site its public address and the
target:

```php
return (new Site(getenv('SITE_URL') ?: 'https://owner.github.io/repository/'))
    // ... providers, renderer, assets, as anywhere
    ->notFound('/404')
    ->githubPages();              // or: omnibridge-static build site.php --target=github-pages
```

- **The base path.** The application renders its pages at the root of the
  origin, as it always does (`RequestRenderer` given the origin, not the
  folder: `(new Site($url))->origin()`). Once the pages are written, every
  internal address of the export is moved under the folder (`Output\BasePath`):
  in HTML every address the exporter reads (links, forms, `src`, `srcset`,
  `poster`, `<link>`, a style's `url()`, a refresh, the
  `Site::resourceAttributes()` attributes - a search page's index URL), in
  style sheets their `url()` and `@import`, in JSON the strings that are the
  root-relative address of a page or file of the export, and everywhere (HTML,
  CSS, JSON, feeds, `sitemap.xml`, `robots.txt`) the absolute URLs of the
  origin - canonical and `hreflang` links, Open Graph, JSON-LD, a share link's
  percent-encoded URL. `sitemap.xml` and the redirection pages are written at
  the base URL. An address already under the folder is left as it is (an
  application that knows its base path writes them so).
- **404.html** at the root of the export, which Pages serves for any unknown
  address under the site: its relative addresses are resolved against the
  address it was rendered at (it answers at any depth). Pages also answers
  `/a/b` with a redirection to `/a/b/` when `a/b/index.html` is the file, so
  every page's relative addresses are resolved too.
- **`.nojekyll`** (no Jekyll: files and folders starting with `_` are served),
  **`CNAME`** when the base URL's host is a custom domain (a custom domain is
  served at its root: a base URL with a path and a custom domain is refused).
- **Redirections** as small pages only: Pages reads no `_redirects`
  (`redirects.json` stays, at the public addresses).
- **The check.** Every written HTML, CSS, JSON, XML and text file is read
  again: an address left outside the folder is an error (`outside-base`), and
  `omnibridge-static check dist` runs the same check on a folder before it is
  published. A warning (`github-pages`) when there is no page for unknown
  addresses, a file above 100 MB or a site above 1 GB.
- **Not moved:** what a script builds at run time (`fetch("/api")` in a
  script). A script reads its addresses from the page - a `data-` attribute
  named by `Site::resourceAttributes()`, `document.baseURI` - or from a
  bundler's public path that names the folder.

The workflow (a repository whose Settings → Pages → Source is "GitHub
Actions"):

```yaml
permissions:
  contents: read
jobs:
  build:
    runs-on: ubuntu-24.04
    permissions: { contents: read, pages: read }
    steps:
      - uses: actions/checkout@v4
      - id: pages
        uses: actions/configure-pages@v6
      # ... PHP, dependencies
      - run: vendor/bin/omnibridge-static build site.php --out=dist --target=github-pages --strict
        env:
          SITE_URL: ${{ vars.SITE_URL || steps.pages.outputs.base_url }}
      - run: vendor/bin/omnibridge-static check dist
      - uses: actions/upload-pages-artifact@v5
        with: { path: dist, include-hidden-files: true }   # .nojekyll
  deploy:
    needs: build
    runs-on: ubuntu-24.04
    permissions: { pages: write, id-token: write }
    environment: { name: github-pages, url: '${{ steps.deployment.outputs.page_url }}' }
    steps:
      - id: deployment
        uses: actions/deploy-pages@v5
```

(Pin the actions by commit SHA in a real workflow.) To try the folder
locally, mount the export under it and serve it as Pages does - a folder
redirected to its address with a slash, `404.html` for the rest:

```nginx
server {
    listen 80;
    root /srv/www;                 # the export mounted at /srv/www/repository
    index index.html;
    absolute_redirect off;
    location / { }
    error_page 404 /repository/404.html;
}
```

## The Symfony and Artisan commands

Thin wrappers over the same `Console\Cli`, each in its host's bridge and
registered only when this package is installed:

- omnibridge/symfony: `bin/console omnibridge:static:build static.php --out=dist`
  (the kernel's renderer, its router's routes, its container, `public/`).
- omnibridge/laravel: `php artisan omnibridge:static:build static.php --out=dist`
  (the HTTP kernel's renderer, the container, `public/`).

## Verified, and not

Verified (2026-10-24):

- The suite: 71 tests (contracts, providers, renderers, links, manifests, the
  exporter, the base path and the GitHub Pages target, the command line and
  the plain script without Composer, the example), PHP 8.2; PHPStan at max,
  no baseline entry.
- GitHub Pages (2026-10-24): the Omnisense website (Symfony, omnibase 4, its
  `static.php`) built for `https://glitchr-omnisense.github.io/omnisense/`:
  1419 pages, 2254 files, 612 redirections, 47 MB, no diagnostic, no address
  outside `/omnisense/`; two builds identical (`diff -r`); its root build
  unchanged but for the pages whose text changed. Served under `/omnisense/`
  by an nginx that answers as Pages does (folders redirected to a slash,
  404.html), headless Chrome on 10 pages at two widths and both colour
  schemes (landing in two languages, a guide, the package list and a
  package, a manual page, the news, the search with its index, 404.html at
  one and four levels): no console error, no failed request, no request nor
  link outside the folder. Not deployed: the repository is not created yet.
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
- The wrappers, their working copies loaded by hand (not yet installed in
  either application): `omnibridge:static:build` on lesringostars gives the
  same report as the plain command line but for the dev 404 page (Symfony's
  exception page, its trace); the artisan command on lesringostars-laravel
  rendered the twin's home, intro, destinations, two articles and the travels
  pages through Laravel, the routes read from lesringostars' actions.
- A long-lived process shows what a service keeps between requests: on
  lesringostars, omnibase's AdvancedRouter (a localized route memoised under
  its canonical name: the first page's language stayed in every later page's
  links) and HtmlTagRenderer (a page's style sheet printed on the next pages).
  Both reset between requests since omnisense/omnibase d92b04c3
  (ResetInterface); the export of lesringostars f4b94b7d needs no workaround.

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
- A site under a folder of its origin moves what the export reads and the
  origin's absolute URLs; an inline script's root-relative strings
  (`fetch("/api")`) are not read, and neither the export nor its check sees
  them. Same-origin absolute URLs are all taken for the site's own: another
  project site of the same `<owner>.github.io` linked absolutely would be
  moved under the folder too.
- GitHub Pages' own behaviour (the slash redirection, 404.html at any depth)
  was reproduced with nginx, not observed on Pages.
- Neither wrapper has run from an installed package (omnibridge/static is not
  yet on Packagist); the Laravel command has not exported a whole site.
- The dev environment's 404 page is Symfony's exception page, which differs
  from one build to the next; a deployable build is made in prod (on
  lesringostars, rendered at its production origin: 243 pages and two builds
  with the same report at first; later the same day the six search pages
  answered 500 in a prod container compiled afresh, a constructor of the site
  given another repository, outside this package).
