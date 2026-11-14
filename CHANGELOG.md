# Changelog

## [Unreleased]

## [1.1.0] - 2026-11-14

Incremental builds by dependency tracking: what the exporter can observe without the site's help
now guards the cache ([docs/incremental.md](docs/incremental.md#what-the-exporter-sees-by-itself-the-build-record)).
Backward compatible: no signature changed, `omnibridge-static.json` is the same file.

### Added

- The build record, `<cache>/omnibridge-static.build.json` (`Build\Record`), written beside the
  cached answers by every export with a cache. It holds the package's code, the environment (the PHP
  version, every extension and its version, every installed package but the root as
  `version@reference`; `Build\Environment`), every PHP file the process loaded with its SHA-256, each
  declared input's fingerprint, and each address rendered or reused with its role, fingerprint and
  declared inputs. No date.
- `Site::ignoreLoaded(...$patterns)`: loaded PHP files the record does not compare, such as the
  application's own cache folder (`__DIR__.'/var/cache/*'`).
- The export tells the declared inputs that changed since the last export (`changed since the last
  export: 1 declared input (...)`).

### Changed

- An export with a cache is a full one, its summary saying why, when the cache's record does not vouch
  for it: no record (a first export, a cache from 1.0.0, an export cut short), another format, another
  version of the package, another PHP version, extension or installed package, or a PHP file the last
  export loaded that changed or is gone. An undeclared PHP template or class change no longer leaves a
  stale page in the cache. The first export into a fresh cache now reads `full (no record of an
  earlier export in the cache)` instead of `incremental`; the files it writes are the same.
- The record is removed when an export starts and written again when it ends: a cache that an
  interrupted export filled is not trusted.

## [1.0.0] - 2026-11-13

The first stable release: `composer require omnibridge/static` installs with Composer's default
stability. The package requires PHP 8.2 and nothing else. What it holds since the first commit:

### Added

- Incremental exports ([docs/incremental.md](docs/incremental.md)):
  - The export is made in a staging folder beside the target, then moved into it. Only the files
    whose bytes changed are written, and the files no longer exported are removed. The target ends
    up as an export into an empty folder would leave it, byte for byte, whatever it held.
  - `Site::dependsOn($shared, $pages)` declares what the renderings depend on: files, folders, glob
    patterns or plain values. `Site::cache($dir)` (or `--cache=`) keeps the renderer's answers by
    the fingerprint of those inputs. A page whose inputs did not change is not rendered again, and a
    page without declared inputs is rendered every time.
  - `--only=<pattern>,...` (`Exporter::export(..., only: [...])`) is a targeted rebuild: the
    addresses it names are rendered whatever the cache holds. Any other address is reused only when
    its declared inputs prove it unaffected, and the command says how many were rendered for want of
    them.
  - `--full` (`full: true`) renders everything and rewrites every file. An earlier export made by
    another version of the package forces a full export.
  - `Report::$summary` (`Build\Summary`) holds the counts: rendered, reused, targeted, undeclared,
    written, unchanged and removed. The command line prints them on its last line.
  - `omnibridge-static.json` gains `build.code` (the SHA-256 of the package's `src/`) and, for each
    rendered file whose inputs are declared, `inputs` (their fingerprint). It holds no count, so a
    full and an incremental export write the same report.
- `StaticResponse::bytes()`: a file exported at a path without an extension and served as
  `application/octet-stream`.
- A redirection carries the visitor's `#fragment` over (`StaticResponse::redirect(..., fragment:)`,
  `X-Static-Fragment`).
- A GitHub Pages target: a site under a folder of its origin, `.nojekyll`, `CNAME`, every address
  checked to stay under the folder.
- The manual in `docs/`.

### Changed

- An export no longer empties its folder first. A build that stops half-way leaves the folder as it
  was; the next build replaces its staging folder (`.<name>.staging`).

### The first commit (2026-10-24)

The generic static exporter of the master plan (section 7, phase 2):

- the addresses, from explicit lists, a router's routes with their parameters' values, or a sitemap;
- each address rendered by the application's own code;
- a deterministic folder: pages, assets, the files the pages refer to, redirections, `404.html`,
  `sitemap.xml`, and a report with checksums and diagnostics;
- `omnibridge-static build` and `check`.
