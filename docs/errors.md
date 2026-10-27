---
title: Errors
order: 50
---

# Errors

## Diagnostics

The export does not stop at a page's failure: every finding is a `Diagnostic` in the report and in
`omnibridge-static.json`. An error fails the build (exit 1), a warning too with `--strict`, a notice
never.

| Code (`Diagnostic::`) | Level | When |
|---|---|---|
| `render-failed` (`RENDER_FAILED`) | error | a page answered a status other than 2xx or 3xx, or its renderer threw (the exception's class and message) |
| `render-failed` | warning | the page for unknown addresses answered something else than 404 |
| `duplicate-output` (`DUPLICATE_OUTPUT`) | error | two pages for one file, a file where a folder is needed or the reverse (the first kept) |
| `duplicate-page` (`DUPLICATE_PAGE`) | warning | the same path given twice (the first kept) |
| `broken-link` (`BROKEN_LINK`) | error | an internal link that resolves to nothing in the export: the target, the first page linking to it and how many more; a document whose links could not be read |
| `missing-asset` (`MISSING_ASSET`) | error | a manifest that cannot be read, a file it names not in the public folder, a copy pattern that matches nothing, copies without a public folder |
| `not-deterministic` (`NOT_DETERMINISTIC`) | error | with `--verify`, a page rendered twice with two different bodies (normalised): where they first differ |
| `not-exportable` (`NOT_EXPORTABLE`) | error | a page that is neither HTML nor a path with an extension nor `bytes()`; a redirection at an address that names a folder |
| `skipped-route` (`SKIPPED_ROUTE`) | warning | a provider left a route out (no source of values, not GET, a value its requirement refuses; a sitemap's address with a query or on another host) |
| `query-ignored` (`QUERY_IGNORED`) | warning | a link with a query string to an exported page |
| `dynamic` (`DYNAMIC`) | notice | links left to, and forms posted to, the dynamic host, counted per address |
| `outside-base` (`OUTSIDE_BASE`) | error | a written file with an internal address outside the site's base path, or whose addresses could not be moved |
| `github-pages` (`GITHUB_PAGES`) | warning | for GitHub Pages: no page for unknown addresses, a file above 100 MB, a site above 1 GB |

## Exceptions

| Exception | Thrown by | When |
|---|---|---|
| `InvalidArgumentException` | `Site::__construct()`, `githubPages()` | a base URL that is no origin or folder of one; a custom domain with a path |
| `InvalidArgumentException` | `StaticPage::__construct()` | a relative path, a query string, a fragment |
| `InvalidArgumentException` | `Cli::load()`, `build()`, `check()` | no such file, no `Site` returned, an unknown target, no report in the folder (exit 2) |
| `InvalidArgumentException` | `SitemapProvider::__construct()` | neither a renderer nor a file |
| `InvalidArgumentException` | `ActionRenderer::render()` | no action, an action not in the container, a parameter with no value (a `render-failed` error) |
| `LogicException` | `Site::renderer()` | no renderer |
| `LogicException` | `RequestRenderer::__construct()` | symfony/http-foundation missing |
| `RuntimeException` | `Exporter::export()` | the output folder holds files and no earlier export, or cannot be made: the build stops (from the command line, PHP's uncaught exception and exit code 255) |
| `RuntimeException` | `SitemapProvider::pages()` | an unreadable or invalid sitemap: a provider's exception is not caught, the build stops |
| `RuntimeException` | `AssetManifest::read()` | an unreadable manifest: caught by the exporter, a `missing-asset` error |
| `JsonException` | `AssetManifest::parse()`, `Cli::check()` | invalid JSON |

> [!NOTE]
> `Cli::run()` turns only `InvalidArgumentException` into exit code 2: a folder that is no earlier
> export reaches the command line as an uncaught `RuntimeException`, and the Symfony and Artisan
> commands as their console's error.
