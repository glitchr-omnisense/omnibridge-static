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

On 2026-11-13, for incremental exports ([Incremental exports](incremental.md)): the suite in
`omnibridge-core:php8.2` and `ghcr.io/glitchr-studio/php:8.4`, 84 tests, 348 assertions, green;
PHPStan at max, no baseline entry; the CI export job's steps (strict build, check, a second build
with the same report, an incremental build into the earlier folder equal to the clean one, the
GitHub Pages build); the two consumers below.

On 2026-11-13, for 1.0.0: an empty Composer project with no stability setting, this checkout as a
path repository presented as 1.0.0; `composer require omnibridge/static:^1.0` installs it alone, and
`examples/plain` builds with `vendor/bin/omnibridge-static build site.php --out=dist --strict` (3
pages, 11 files, 1 redirection, no diagnostic) and checks (`11 files checked, 0 problems`), in
`ghcr.io/glitchr-studio/php:8.4`. No code changed for the release, so the suite was not run again.

## As the README states


### Verified (2026-10-24, and below 2026-11-13)

- Incremental exports (2026-11-13): the suite, 84 tests, 348 assertions, green in PHP 8.2
  and 8.4, PHPStan at max with no baseline entry. `IncrementalExportTest` asserts that a full
  and an incremental export give the same tree after a changed page, a changed route list, a
  changed shared asset and a changed layout, from a folder edited by hand, from an empty folder
  with a warm cache, and from an export by another version. The Omnisense website (its
  `static.php`, 1183 pages, 3410 redirections, 4817 files, GitHub Pages under `/omnisense/`), on
  a scratch copy with this checkout as a path repository:
  - unchanged, its folder is the earlier package's but for `build.code`;
  - a second build writes nothing (`0 written, 4817 unchanged`).
  - With a `Site::dependsOn()` added on that copy only (manual pages by their package's fetched
    files, the other pages by every content file), a warm build renders nothing (`0 rendered,
    4594 reused`, 49 s against 72 s cold, on a loaded machine).
  - After a manual's page and a news item were edited: `708 rendered, 3886 reused; 6 written`.
    `diff -r` against a `--full` build into an empty folder: identical, before and after the
    edit.
  - A unity-hub-template copy (omnisense/omnihub's `build --strict`): exit 0, its second
    build `2 written, 3 unchanged`. The two are `-/all` and `-/v1/search`, whose `_updated` is
    the build's time: omnihub writes it, not this package.
- The suite (2026-10-24): 71 tests (contracts, providers, renderers, links, manifests, the
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
- Incremental builds: the writing is always incremental; the renderings only
  where the site declares their inputs (`Site::dependsOn()`), which nothing
  checks: an undeclared dependency gives a stale page from the cache. A
  dependency between pages is known only through the files they declare. The
  Symfony and Artisan commands pass `--cache`, `--only` and `--full` from
  omnibridge/symfony 1.1.0 and omnibridge/laravel 1.1.0 on (each bridge's own
  test); neither has made an incremental export of a whole site.
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
- From 1.0.0 the package installs with Composer's default stability
  (`composer require omnibridge/static`), checked on 2026-11-13 in an empty
  project with this checkout presented as 1.0.0, before the tag. Neither
  wrapper has run from an installed package; the Laravel command has not
  exported a whole site.
- The dev environment's 404 page is Symfony's exception page, which differs
  from one build to the next; a deployable build is made in prod (on
  lesringostars, rendered at its production origin: 243 pages and two builds
  with the same report at first; later the same day the six search pages
  answered 500 in a prod container compiled afresh, a constructor of the site
  given another repository, outside this package).
