<?php

namespace Omnibridge\Static;

/**
 * What an export wrote and found: the files (their path in the export, their
 * SHA-256 and size), the pages and where each went, the redirections, the
 * diagnostics. The same report is written as omnibridge-static.json in the
 * export, without a date: two builds of the same application give the same
 * file, so CI can compare them.
 */
final class Report
{
    /**
     * @param array<string, array{sha256: string, size: int}>                                  $files       path in the export => checksum and size, sorted
     * @param array<string, array{file: string, status: int, route: ?string, locale: ?string}> $pages       page path => what was written, sorted
     * @param array<string, array{to: string, status: int}>                                    $redirects   path => target, sorted
     * @param list<Diagnostic>                                                                 $diagnostics in the order they were found
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly array $files,
        public readonly array $pages,
        public readonly array $redirects,
        public readonly array $diagnostics,
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

    /** The report as omnibridge-static.json holds it: stable keys, no date. */
    public function toJson(): string
    {
        $diagnostics = array_map(static fn (Diagnostic $diagnostic) => $diagnostic->jsonSerialize(), $this->diagnostics);
        usort($diagnostics, static fn (array $a, array $b) => [$a['level'], $a['code'], $a['subject'], $a['message']] <=> [$b['level'], $b['code'], $b['subject'], $b['message']]);

        return json_encode([
            'generator' => 'omnibridge/static',
            'baseUrl' => $this->baseUrl,
            'pages' => (object) $this->pages,
            'redirects' => (object) $this->redirects,
            'files' => (object) $this->files,
            'diagnostics' => $diagnostics,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }
}
