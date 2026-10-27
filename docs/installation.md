---
title: Installation
order: 10
---

# Installation

## Requirements

PHP 8.2 or later, nothing else. Three packages are suggested, each needed by one class only:

| Package | Needed by |
|---|---|
| `symfony/routing` (6.4, 7 or 8) | `Route\RouteCollectionProvider` |
| `symfony/http-foundation` (6.4, 7 or 8) | `Render\RequestRenderer` (its constructor says so with a `LogicException` when it is missing) |
| `psr/container` (1.1 or 2) | `Render\ActionRenderer` |

```sh
composer require omnibridge/static:1.x-dev
```

## The command line

`vendor/bin/omnibridge-static` (`bin/omnibridge-static`): loads the project's Composer autoloader when
the package is installed in one (never the package's own `vendor/`), and the package's own classes
from `src/` otherwise - so a checkout runs it with no Composer at all.

```text
omnibridge-static build <site.php> [--out=dist] [--target=github-pages] [--verify] [--strict] [--quiet]
omnibridge-static check <dist>
```

| Argument or option | Default | Effect |
|---|---|---|
| `build <site.php>` | `static.php` | the file that returns a `Site` (or a closure that returns one) |
| `--out=` | `dist` | the folder to export to: created, or emptied when it holds an earlier export (an `omnibridge-static.json`); a folder holding anything else is refused |
| `--target=github-pages` | none | `Site::githubPages()` ([GitHub Pages](github-pages.md)); another value is refused |
| `--verify` | off | every page rendered twice; a page that differs is an error (`not-deterministic`) |
| `--strict` | off | a warning fails the build too |
| `--quiet` | off | no progress lines; of the diagnostics, the errors only; the counts stay |
| `check <dist>` | `dist` | every file of the report there with its checksum, no file more, and, for a site under a folder of its origin, no internal address outside that folder |

Options are written `--name=value` or `--name`; a value separated by a space is read as an argument.
Every line goes to standard error.

| Exit code | When |
|---|---|
| 0 | built (or checked) with no error (and no warning with `--strict`) |
| 1 | an error (or, with `--strict`, a warning); `check`: a problem |
| 2 | an invalid argument: no such file, a file that returns no `Site`, an unknown target, a folder without `omnibridge-static.json` for `check`; no command or an unknown one (the usage is printed) |

## The example, built and checked

`examples/plain/site.php`: two posts in a PHP array rendered by PHP templates, a Webpack-style
manifest naming the style sheet, the font the style sheet refers to, `robots.txt`, a redirection
(`/old-blog` to `/`), a page for unknown addresses. Built at the root, checked, then built for GitHub
Pages under `https://owner.github.io/example/` (`STATIC_ORIGIN`) and checked, in the package's
image `omnibridge-core:php8.2` (2026-10-27):

```text
$ omnibridge-static build site.php --out=/out/dist
5/5 pages rendered
3 pages, 11 files, 1 redirections; 0 errors, 0 warnings
exit 0
$ find /out/dist -type f | sort
./404.html
./_redirects
./build/app.3f2a1c.css
./build/example.woff2
./index.html
./old-blog/index.html
./omnibridge-static.json
./posts/hello/index.html
./posts/static/index.html
./redirects.json
./robots.txt
./sitemap.xml
$ cat _redirects
/old-blog / 301
$ cat old-blog/index.html
<!DOCTYPE html><html><head><meta charset="utf-8"><title>/</title><meta http-equiv="refresh" content="0; url=/"><link rel="canonical" href="/"><meta name="robots" content="noindex"></head><body><a href="/">/</a></body></html>
$ omnibridge-static check /out/dist
11 files checked, 0 problems
exit 0
$ STATIC_ORIGIN=https://owner.github.io/example/ omnibridge-static build site.php --out=/out/pages --target=github-pages --strict
5/5 pages rendered
3 pages, 11 files, 1 redirections; 0 errors, 0 warnings
exit 0
./.nojekyll
./404.html
./build/app.3f2a1c.css
./build/example.woff2
./index.html
./old-blog/index.html
./omnibridge-static.json
./posts/hello/index.html
./posts/static/index.html
./redirects.json
./robots.txt
./sitemap.xml
$ grep -o "href=\"[^\"]*\"" posts/hello/index.html
href="/example/build/app.3f2a1c.css"
href="/example/"
href="/example/"
11 files checked, 0 problems
exit 0
$ head -20 omnibridge-static.json (the root build)
{
    "generator": "omnibridge/static",
    "baseUrl": "https://example.org",
    "pages": {
        "/": {
            "file": "index.html",
            "status": 200,
            "route": null,
            "locale": null
        },
        "/posts/hello": {
            "file": "posts/hello/index.html",
            "status": 200,
            "route": null,
            "locale": null
        },
        "/posts/static": {
            "file": "posts/static/index.html",
            "status": 200,
            "route": null,
```

- Five pages rendered: the three listed, the redirection and the page for unknown addresses; three
  pages in the report (`/`, the two posts), one redirection.
- The font is found by following the style sheet's `url()`; the style sheet by the manifest.
- For GitHub Pages: `.nojekyll`, no `_redirects`, every internal address moved under `/example/`.
- The file count (11) leaves out `omnibridge-static.json` itself.
