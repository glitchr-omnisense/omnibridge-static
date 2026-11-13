<?php

namespace Omnibridge\Static;

use Omnibridge\Static\Build\Summary;

/**
 * What an export wrote and found: the files (their path in the export, their
 * SHA-256 and size), the pages and where each went, the redirections, the
 * diagnostics. The same report is written as omnibridge-static.json in the
 * export, without a date: two builds of the same application give the same
 * file, so CI can compare them.
 *
 * The report is also the manifest a later export starts from: the version of
 * the package that wrote it (build.code), and for each file a rendering
 * wrote, the fingerprint of its inputs when the site declares them
 * (Site::dependsOn()). What the export did to reach its files - rendered or
 * reused, written, unchanged or removed - is $summary, which the file does
 * not hold: a full and an incremental export write the same report.
 */
final class Report
{
    /**
     * @param array<string, array{sha256: string, size: int, inputs?: string}>                  $files       path in the export => checksum, size and, for a rendered file whose inputs are declared, their fingerprint; sorted
     * @param array<string, array{file: string, status: int, route: ?string, locale: ?string}> $pages       page path => what was written, sorted
     * @param array<string, array{to: string, status: int}>                                    $redirects   path => target, sorted
     * @param list<Diagnostic>                                                                 $diagnostics in the order they were found
     * @param string|null                                                                      $code        the fingerprint of the package's code that wrote it (Build\Fingerprint::code())
     * @param Summary|null                                                                     $summary     what the export did to reach its files
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly array $files,
        public readonly array $pages,
        public readonly array $redirects,
        public readonly array $diagnostics,
        public readonly ?string $code = null,
        public readonly ?Summary $summary = null,
    ) {
    }

    /** @return list<Diagnostic> */
    public function errors(): array
    {
        return $this->level(Diagnostic::ERROR);
    }

    /** @return list<Diagnostic> */
    public function warnings(): array
    {
        return $this->level(Diagnostic::WARNING);
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors();
    }

    /** @return list<Diagnostic> */
    public function level(string $level): array
    {
        return array_values(array_filter($this->diagnostics, static fn (Diagnostic $diagnostic) => $diagnostic->level === $level));
    }

    /** @return array<string, int> code => how many, sorted by code */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->diagnostics as $diagnostic) {
            $key = $diagnostic->level.' '.$diagnostic->code;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /** The same report, with what the export did to reach it. */
    public function withSummary(Summary $summary): self
    {
        return new self($this->baseUrl, $this->files, $this->pages, $this->redirects, $this->diagnostics, $this->code, $summary);
    }

    /** The report as omnibridge-static.json holds it: stable keys, no date. */
    public function toJson(): string
    {
        $diagnostics = array_map(static fn (Diagnostic $diagnostic) => $diagnostic->jsonSerialize(), $this->diagnostics);
        usort($diagnostics, static fn (array $a, array $b) => [$a['level'], $a['code'], $a['subject'], $a['message']] <=> [$b['level'], $b['code'], $b['subject'], $b['message']]);

        return json_encode([
            'generator' => 'omnibridge/static',
            'build' => (object) (null !== $this->code ? ['code' => $this->code] : []),
            'baseUrl' => $this->baseUrl,
            'pages' => (object) $this->pages,
            'redirects' => (object) $this->redirects,
            'files' => (object) $this->files,
            'diagnostics' => $diagnostics,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }
}
