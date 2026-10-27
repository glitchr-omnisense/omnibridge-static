---
title: Reference
order: 40
---

# Reference

## Site

`Omnibridge\Static\Site` (final). `new Site(string $baseUrl)`: the public address of the static site -
its origin (`https://example.org`) or a folder of one (`https://owner.github.io/repository/`); a URL
without scheme or host, with a query or a fragment, or whose path is no folder (`..`, characters a
path segment cannot hold) is an `InvalidArgumentException`. Every setter returns the site.

| Method | Default | Effect |
|---|---|---|
| `provide(StaticRouteProviderInterface ...$providers)` | none | where the addresses come from ([Addresses](addresses.md)) |
| `renderWith(StaticRendererInterface $renderer)` | none: `renderer()` throws `LogicException` ("The site names no renderer") | how a page is rendered ([Rendering](rendering.md)) |
| `publicDir(string $dir)` | none | the public folder files are copied from |
| `assets(string $manifest, string $base = '')` | none | a front-end build's manifest; `base` is the public path a Vite manifest's files are relative to (`/build`) |
| `copy(string ...$patterns)` | none | public files copied as they are, `fnmatch` patterns relative to the public folder; without `publicDir()`, a `missing-asset` error |
| `notFound(string $path = '/404')` | none | the address rendered for `404.html` |
| `dynamic(array $patterns, ?string $host = null)` | none | addresses left to the dynamic host; its origin to rewrite links and posted forms to |
| `normalise(\Closure $normaliser)` | none | `fn (string $html, StaticPage $page): string`, applied to every HTML page in order |
| `followResources(bool $follow = true)` | on | render and write the files the application serves that the pages link to |
| `resourceAttributes(string ...$attributes)` | none | more attributes naming a file the page's scripts load |
| `sitemap(bool $sitemap = true)` | on | write `sitemap.xml` |
| `githubPages(bool $githubPages = true)` | off | export for GitHub Pages ([GitHub Pages](github-pages.md)) |

Read back: `basePath()` (`/repository`, `''` at the root), `origin()` (scheme, host, port, lower case),
`customDomain()`, `isForGitHubPages()`, `providers()`, `renderer()`, `getPublicDir()`, `manifests()`,
`copies()`, `notFoundPage()`, `dynamicHost()`, `normalisers()`, `followsResources()`,
`writesSitemap()`, `getResourceAttributes()`, `isDynamic(string $path)`.

## StaticPage

| Property | Type | Default | Meaning |
|---|---|---|---|
| `path` | `string` | required | absolute, no query, no fragment (`InvalidArgumentException` otherwise) |
| `route` | `?string` | null | the route it was generated from, for the diagnostics |
| `parameters` | `array` | `[]` | the route's parameters |
| `action` | `?string` | null | `Class` or `Class::method` |
| `locale` | `?string` | null | its language |
| `alternates` | `array<string, string>` | `[]` | language => path of the same page |
| `headers` | `array<string, string>` | `[]` | request headers to render it with |
| `sitemap` | `bool` | true | listed in `sitemap.xml` |
| `role` | `string` | `StaticPage::PAGE` | `PAGE` (`page`), `NOT_FOUND` (`not-found`), `RESOURCE` (`resource`: a file found by following links) |

`StaticPage::notFound(string $path = '/404')`, `withAlternates(array)`, `describe()` (`/path (route)`).

## StaticResponse

| Property | Type | Meaning |
|---|---|---|
| `status` | `int` | the HTTP status |
| `body` | `string` | the content, as it is written |
| `contentType` | `?string` | the `Content-Type` |
| `location` | `?string` | where a redirection sends |
| `fragment` | `?string` | the prefix the visitor's `#fragment` gains on a redirection; `''` as it is; null dropped |

| Factory | Status | Type |
|---|---|---|
| `html(string $body, int $status = 200)` | | `text/html; charset=UTF-8` |
| `json(string $body, int $status = 200)` | | `application/json` (exported only at a path with an extension) |
| `bytes(string $body, int $status = 200)` | | `application/octet-stream`, exported at its path, extension or not |
| `redirect(string $location, int $status = 301, ?string $fragment = null)` | 301 | |
| `notFound(string $body = '')` | 404 | `text/html; charset=UTF-8` |

`StaticResponse::FRAGMENT_HEADER` is `X-Static-Fragment`. Predicates: `isSuccessful()` (2xx),
`isRedirect()` (3xx with a location), `isHtml()` (`text/html`, `application/xhtml+xml`), `isCss()`,
`isBytes()`, `mediaType()`; `withBody(string)`.

## Report

What `Exporter::export()` returns and writes as `omnibridge-static.json`: `baseUrl`, `files`
(path => `{sha256, size}`), `pages` (path => `{file, status, route, locale}`), `redirects`
(path => `{to, status}`), `diagnostics` (`list<Diagnostic>`, in the order found). `errors()`,
`warnings()`, `level($level)`, `hasErrors()`, `counts()` (`"<level> <code>"` => count, sorted),
`toJson()` (stable keys, the diagnostics sorted, no date).

`Diagnostic`: `level` (`error`, `warning`, `notice`), `code`, `subject`, `message`; printed
`[level] code subject: message` ([Errors](errors.md)).

## The helpers

- `Output\OutputPath`: `page($path)`, `file($path)` (a folder is an `InvalidArgumentException`),
  `hasExtension($path)`, `key($path)` (decoded, no trailing slash: how the exporter compares addresses).
- `Asset\AssetManifest::read($file, $base = '')`, `parse($json, $base = '')`: the public paths a
  manifest names, sorted, once each; another host's addresses left out.
- `Link\Links`: reads and rewrites the addresses of HTML and CSS (kinds `page`, `form`, `post`,
  `resource`); `Output\BasePath`: moves addresses under a folder and finds those left outside.
