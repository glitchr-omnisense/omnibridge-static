---
title: Testing
order: 60
---

# Testing

## Testing an export

- In CI: `omnibridge-static build site.php --out=dist --strict`, then `omnibridge-static check dist`,
  then build again into another folder and compare (`diff -r`, or the two `omnibridge-static.json`):
  two builds of the same application must be the same folder.
- `--verify` renders every page twice in the same process: a page that differs shows a service that
  keeps state between requests.
- Against the dynamic site: render the same addresses through the host and compare page by page,
  normalised as the export is (lesringostars' parity suite does it).
- In PHPUnit, `Exporter::export()` returns the `Report`: assert `errors()` is empty, the pages and the
  redirections, then read the folder.

## The package's suite

```sh
docker compose run --rm test
```

The service `test` uses the image `omnibridge-core:php8.2` (`docker/Dockerfile`: PHP 8.2-cli with git,
unzip and Composer 2, no extension added), the package at `/package`, a named volume
`composer-cache`; it runs `composer update` then `vendor/bin/phpunit`. With the checkout's `vendor/`
installed:

```sh
docker run --rm --network none -v "$PWD":/package:ro omnibridge-core:php8.2 vendor/bin/phpunit --do-not-cache-result
```

| Test class | What it checks |
|---|---|
| `BareTest` | every class loads without any other package |
| `ExporterTest` | pages written as a static host serves them; the files the pages need copied or rendered; a redirection a small page and a line; the sitemap lists the pages and their languages; every link checked and the dynamic ones sent to the dynamic host; the report written and two builds the same; an earlier export replaced, any other folder refused; a page rendered differently twice an error unless normalised; what cannot be a static file an error |
| `GitHubPagesTest` | a redirection carries the visitor's fragment under the base path; the export moved under the base path; the files GitHub Pages reads; a custom domain served at the root with a CNAME file; two builds the same and the check passes; the command line targets GitHub Pages; a base URL is an origin or a folder of one |
| `Console\CliTest` | build exports the site the file declares and fails on its errors; check compares an export with its report; a file that declares no site is a usage error; the example site exports without an error; the plain PHP script runs without Composer |
| `Route\RouteCollectionProviderTest` | a route without variables is one page; a parameterised route exported for the values its source gives; a route without a source or without GET left out and said so; a localized route's languages alternates of one another; routes chosen by name and by action |
| `Route\SitemapProviderTest` | the sitemap's addresses are the pages; a sitemap index followed; a sitemap file |
| `Render\RequestRendererTest` | the page is a visitor's GET of its address on the base URL; a redirection, a file and a stream |
| `Render\ActionRendererTest` | the route's action called with its parameters and services; an action that finds nothing is no such page; a page without an action refused |
| `Link\LinksTest` | the markup's addresses read by kind; more resource attributes; a style sheet's urls and imports; links and forms rewritten, scripts and other tags not; a refresh and an SVG's references read; every address read can be rewritten |
| `Output\OutputPathTest`, `Output\BasePathTest` | where a page and a file are written, an address leaving the export refused, keys, references resolved; addresses under the base path or not, moved in HTML, CSS, JSON, feeds and text, 404.html's relative addresses resolved, what is left outside found |
| `Asset\AssetManifestTest` | Vite's files relative to its folder; Webpack's public paths; Encore's entrypoints; a missing manifest |

The CI also builds `examples/plain`, checks it, builds it again and compares, and keeps the folder as
an artifact.
