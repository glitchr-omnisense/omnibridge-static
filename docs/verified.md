---
title: Verified, and not
order: 90
---

# Verified, and not

## Run for this manual

On 2026-10-27, in `omnibridge-core:php8.2` (PHP 8.2), the checkout's own `vendor/`, no network: 72
tests, 258 assertions, green. The example site built at the root and for GitHub Pages and checked
([Installation](installation.md#the-example-built-and-checked)), and a router-provider export with a
dynamic host and a fragment carried over ([The export](export.md#a-run)), their output pasted.

## As the README states


### Verified (2026-10-24)

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

### Not done, or not verified

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
