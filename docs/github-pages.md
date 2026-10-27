---
title: GitHub Pages
order: 35
---

# GitHub Pages


## The base path and the target

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

## The workflow

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

## What the target changes, in the code

- `Site::githubPages()` refuses a custom domain with a path (`InvalidArgumentException`: "GitHub Pages
  serves a custom domain at its root"); `customDomain()` is the base URL's host unless it ends in
  `.github.io`.
- The command line's `--target=github-pages` calls it; omnibridge/symfony's and omnibridge/laravel's
  commands have no such option: the site's file calls `->githubPages()`.
- Without the target, a base URL with a path still moves every address under the folder and checks
  it (`outside-base`): the target adds `.nojekyll`, `CNAME`, the redirections as pages only, the
  resolution of every page's relative addresses and the size warnings.
- The limits warned of: a file above 100 MB, a site above 1 GB (`github-pages` warnings), no
  `Site::notFound()`.
