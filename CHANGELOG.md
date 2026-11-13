# Changelog

## [Unreleased]

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
