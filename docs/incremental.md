---
title: Incremental exports
order: 35
---

# Incremental exports

Every export writes only what changed. When the site declares what its renderings depend on, an
export also renders only what changed. What no site declares and the exporter can still see - the PHP
files the process loaded, the PHP version and extensions, the installed packages - is recorded beside
the cache, and any change to it makes the next export a full one. Correctness comes before speed. Whatever the folder held
before, an incremental export leaves it byte for byte as a full export into an empty folder would
leave it, `omnibridge-static.json` included (`IncrementalExportTest` proves it, below).

## The writing: always incremental

The export is made in a staging folder beside the target (`.dist.staging` for `dist`), then moved into
the target (`Build\Sync`):

- a file whose bytes are already there is left as it is (its date and inode too);
- any other file is moved in;
- whatever the target holds that the export did not write is removed, with the folders it leaves
  empty: a page no longer listed, a file left by hand, a folder where a file now goes.

The target holds an earlier export (its `omnibridge-static.json`) or nothing; any other folder is
refused, as before. A build that stops half-way leaves the target as it was and its staging folder
behind; the next build replaces that folder. The report's `summary` counts the files `written`,
`unchanged` and `removed`. The command line prints them on its last line:

```text
incremental: 4594 rendered, 0 reused (4594 rendered for want of declared inputs); 0 written, 4817 unchanged, 0 removed
```

(The Omnisense website's second build, its inputs not declared: every page rendered, no file written.)

## The renderings: incremental when the inputs are declared

The exporter cannot see inside a renderer: a kernel's answer may depend on any file, database or
service. A page is reused only when the site says what it depends on (`Site::dependsOn()`) and where
to keep the answers (`Site::cache()` or `--cache=`):

```php
return (new Site('https://example.org'))
    ->provide(...)
    ->renderWith(...)
    ->dependsOn(
        // Every page: the renderer's code, templates, configuration, the front-end build's manifest.
        [__DIR__.'/src', __DIR__.'/templates', __DIR__.'/config', __DIR__.'/composer.lock', __DIR__.'/public/build/manifest.json'],
        // One page's own inputs, beside those; null: not known, rendered every time.
        fn (StaticPage $page): ?array => match (true) {
            'app_news_item' === $page->route => [__DIR__.'/news/'.$page->parameters['slug'].'.md'],
            'app_news' === $page->route => [__DIR__.'/news'],              // the list shows every item
            // A manual's pages: its fetched files (or the commit it is pinned at, a string taken as it is).
            str_starts_with((string) $page->route, 'app_manual') => [__DIR__.'/var/docs/'.$page->parameters['key']],
            default => null,
        },
    )
    ->cache(__DIR__.'/var/static-cache');
```

An input is:

- a file: its content counts, never its path, so the same checkout gives the same fingerprints
  wherever it is;
- a folder: the names of its files relative to it, and their contents. Adding, removing or changing a
  file changes it;
- a glob pattern (`news/*.md`): what it matches, by the same rule;
- any other string, taken as it is: a commit, a version, a value no file shows. A path that does not
  exist is such a string.

A page's fingerprint is the SHA-256 of all of these:

- this package's code;
- the PHP version;
- the site's base URL;
- the shared inputs;
- the page itself: path, route, parameters, action, locale, alternates, headers, sitemap and role;
- its own inputs.

If the cache holds an answer under that fingerprint, the page is not rendered. Its answer is taken
from the cache, and everything after the rendering runs on it exactly as on a fresh one: normalisers,
links followed, the dynamic host, the base path, the sitemap. The closure is asked for every address
rendered:

- pages and redirections;
- the page for unknown addresses;
- the files the pages refer to (`StaticPage::$role` is `resource` for these).

The rules that keep a stale page out:

- A page without declared inputs (the closure returns null, or no closure) is rendered every time. The
  summary counts these pages: "rendered for want of declared inputs".
- A page whose parameters hold an object has no fingerprint, so it is rendered every time.
- A dependency between pages is known only through inputs. A list that shows its items declares the
  items' files (the folder), and an item that changes then renders the list again. Nothing else
  infers it.
- An answer with an error status (4xx or 5xx, except the page for unknown addresses) is not kept: it
  is asked again next time.
- Another version of the package, another PHP version or another base URL never matches: the
  fingerprint changes.
- The command line adds the site's own file to the shared inputs.
- A cache whose record does not vouch for it (below) gives nothing: the export is a full one.
- After an export, the cache holds only the answers that export used (`ResponseCache::prune()`) and
  that export's record. Nothing else in its folder is touched.

Keep the cache outside the export's folder. In CI, keep it between runs with the CI's own cache, keyed
so that a run restores the last one, as the composer cache is. The record is a file of that folder: it
is restored with the answers, and the folder alone is what to restore.

## What the exporter sees by itself: the build record

Declared inputs say what a page is made of. They cannot cover what the site never thinks of: a PHP
template its renderer includes, a class of `src/`, a package upgraded, an extension of another version
(`intl` writes dates). The exporter sees those, for the whole export rather than page by page, and
writes them beside the answers, in `<cache>/omnibridge-static.build.json` (`Build\Record`):

| Entry | What it holds |
|---|---|
| `format`, `code` | the record's format (1) and the SHA-256 of the package's `src/` |
| `environment.php` | the PHP version |
| `environment.extensions` | every loaded extension and its version |
| `environment.packages` | every package Composer installed, `version@reference`, the root package aside (its reference is the checkout's commit; its code is seen through the files it loads) |
| `loaded` | every PHP file the process loaded (`get_included_files()`), with its SHA-256 at the end of the export (`null`: gone by then) |
| `inputs` | every declared input this export fingerprinted, with the SHA-256 of what it read |
| `addresses` | every address rendered or reused: its path, role, fingerprint and declared inputs (`null`: not declared) |

The record holds no date. Its paths are absolute: a checkout moved elsewhere finds its PHP files
"gone", and makes a full export.

An export with a cache reads the record, then removes it until it writes its own at the end: an export
cut short (an exception, a killed process) leaves a cache that the next export does not trust. It
trusts the cache only when all of these hold:

- the record is there, readable and of format 1;
- it was written by this version of the package;
- the PHP version, the extensions and the installed packages are the same;
- every PHP file it lists has the same content, and none is gone (or back).

Otherwise the export is a full one, and its summary says why, in these words:

```text
full (no record of an earlier export in the cache): ...
full (the cache was filled by another version of omnibridge/static): ...
full (PHP 8.4.15, the earlier export ran on 8.4.14): ...
full (the PHP extensions changed: intl): ...
full (the installed packages changed: symfony/http-kernel, twig/twig): ...
full (a PHP file the earlier export loaded changed: /app/templates/post.php): ...
```

The first export into a fresh cache is the first line. The next export after a full one is
incremental again: the record it finds is the full export's.

What the record cannot see:

- what a renderer reads without loading it as PHP: a Twig or Blade template, a Markdown file, a
  YAML configuration, a database. These are declared (`Site::dependsOn()`), or the page is rendered
  every time. A template an engine compiles to PHP (Twig, Blade) is no witness of its source: the
  record is checked before the engine compiles anything, against the compiled file of the last
  export.
- which page used which file: a change makes the whole next export a full one;
- a file changed while an export ran: its content at the end of the export is recorded.

Declared inputs that changed are told before the summary, the five first named:

```text
changed since the last export: 1 declared input (/app/news/2026-11-14-release.en.md)
```

### The application's cache folder: `Site::ignoreLoaded()`

A file the application writes afresh, with the same meaning but other bytes or at another path,
would make every export a full one. An application's own cache folder is the case: a CI run starts
from a fresh `var/cache`, so every compiled file of the last run is gone or rewritten, and Symfony's
compiled container carries its build time (`container.build_time`, the current time unless
`SOURCE_DATE_EPOCH` is set). Leave such files out:

```php
->ignoreLoaded(__DIR__.'/var/cache/*')   // fnmatch patterns of absolute paths; "*" crosses slashes
```

The cache folder is safe to leave out when the sources it is compiled from are declared or loaded:
`src/` is loaded, while `config/` and `templates/` are declared. Where the cache folder is kept
between exports (a developer's machine), leaving it out is still right: it is compiled from those
same sources.

## A targeted rebuild: `--only`

```sh
vendor/bin/omnibridge-static build static.php --cache=var/static-cache --only='/news/*,/fr/actualites/*'
```

The addresses `--only` names (comma-separated `fnmatch` patterns of paths, where `*` crosses slashes)
are rendered whatever the cache holds. This is for a change that no declared input shows, such as a
package's documentation republished under the same pin.

Every other address goes through the ordinary rule: it is reused only when its declared inputs prove
it unaffected. An address whose inputs are not declared is rendered too. The command says so plainly:

```text
--only: 6 addresses outside the patterns rendered too (6 whose inputs are not declared: nothing proves them unaffected; 0 whose inputs changed or are not in the cache)
```

So `--only` never gives an older page than an incremental export would give. Without declared inputs
it renders everything, and only the writing stays incremental. A pattern that matches no address is
reported (`--only: no address matches ...`).

## A full export: `--full`, another version, or a cache not vouched for

`--full` (`Exporter::export(..., full: true)`) takes nothing from the cache and rewrites every file;
the summary says `full (asked)`. An earlier export made by another version of the package forces the
same thing, with the summary `full (the earlier export was made by another version of
omnibridge/static)`. An earlier export from before incremental exports, which has no `build.code` in
its report, counts as another version. The report's `build.code` is the SHA-256 of the package's
`src/`. A cache whose record does not vouch for it forces the same, with the reasons
[above](#what-the-exporter-sees-by-itself-the-build-record).

## What the report holds

`omnibridge-static.json` gains:

- `build.code`;
- for each file a rendering wrote, `inputs`: its fingerprint, when its inputs are declared.

It holds no count, and the build record is in the cache, not in the export's folder: a full and an
incremental export of the same application write the same report and the same files.
The counts are in `Report::$summary` (`Build\Summary`):

| Field | Meaning |
|---|---|
| `mode`, `why` | `full` (with the reason) or `incremental` |
| `rendered` | the addresses rendered: pages, redirections, 404.html, followed files |
| `reused` | answers taken from the cache |
| `targeted` | of the rendered, those `--only` names |
| `undeclared` | of the rendered, those without declared inputs |
| `matched` | the addresses the `--only` patterns matched (null without `--only`) |
| `written`, `unchanged`, `removed` | the files of the folder, the report aside |

## Proved by the suite

`tests/IncrementalExportTest.php` uses a fixture site made of files (`Fixtures\ContentSite`): a
layout, one page per content file, a list page, a redirection, a page for unknown addresses, a picture
the application serves, and a style sheet with its font. Each step below runs an incremental export
on the previous state, then a full export of the same files into an empty folder without a cache, and
asserts the two trees are identical (every file's SHA-256, every folder):

| Step | Rendered | Reused | Written | Unchanged | Removed |
|---|---|---|---|---|---|
| first export | 7 | 0 | 12 | 0 | 0 |
| a page changed (it and the list) | 2 | 5 | 2 | 10 | 0 |
| the route list changed (a page removed, one added) | 2 | 5 | 3 | 9 | 1 |
| a shared asset changed (the style sheet) | 0 | 7 | 1 | 11 | 0 |
| the shared layout changed | 7 | 0 | 5 | 7 | 0 |
| nothing changed | 0 | 7 | 0 | 12 | 0 |

The same holds from a folder edited by hand (a page changed, a foreign file added, a folder where a
file goes, an empty folder), from an empty folder with a warm cache, and from an earlier export by
another version (`full`). `--only` with declared inputs renders its address alone; without declared
inputs it renders everything and says why.

The build record's tests, each compared with a full export into an empty folder in the same way:

| Change | Mode | Rendered | Reused | Written | Unchanged | Removed |
|---|---|---|---|---|---|---|
| none (two clean builds, one with a cache: the same tree and report) | - | 7 | 0 | 12 | - | - |
| a declared configuration file (the title) | incremental, the input named | 7 | 0 | 5 | 7 | 0 |
| a PHP file the renderer includes, declared nowhere | full | 7 | 0 | 12 | 0 | 0 |
| the export after it | incremental | 0 | 7 | 0 | 12 | 0 |
| a PHP file of the record gone | full | | | | | |
| that file left out by `Site::ignoreLoaded()`, rewritten | incremental | 0 | 7 | 0 | 12 | 0 |
| another PHP version, extension, package version, package code, record format; no record | full, each reason checked | 7 | 0 | 12 | 0 | 0 |
| an export cut short by an exception | full (no record) | | | | | |
| a page deleted: the list rendered again, the page's folder, report entry and record entry gone | incremental | 1 | 5 | 2 | 9 | 1 |

`testTheRecordSaysWhatEachAddressDependedOn` reads the record: each address with its declared
inputs, the PHP version, the packages (`phpunit/phpunit` among them, not the root package), each
declared input.
