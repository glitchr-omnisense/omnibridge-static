<?php

namespace Omnibridge\Static;

/**
 * One thing the export found: an error fails the build (a page that did not
 * render, two pages for one file, a link to nothing, a missing asset, a page
 * rendered differently twice), a warning is to be read (a route left out, a
 * page given twice), a notice says what the export did on purpose (a link
 * left to the dynamic host).
 */
final class Diagnostic implements \JsonSerializable
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';

    /** A page answered an error status, or its rendering threw. */
    public const RENDER_FAILED = 'render-failed';
    /** Two pages would be written to one file. */
    public const DUPLICATE_OUTPUT = 'duplicate-output';
    /** The same page was given twice (the first is kept). */
    public const DUPLICATE_PAGE = 'duplicate-page';
    /** An internal link resolves to nothing in the export. */
    public const BROKEN_LINK = 'broken-link';
    /** A file of an asset manifest, or one to copy, is not in the public folder. */
    public const MISSING_ASSET = 'missing-asset';
    /** A page rendered twice gave two different bodies. */
    public const NOT_DETERMINISTIC = 'not-deterministic';
    /** A provider left a route out. */
    public const SKIPPED_ROUTE = 'skipped-route';
    /** A page answered something a static file cannot be (no extension for a type other than HTML...). */
    public const NOT_EXPORTABLE = 'not-exportable';
    /** A link with a query string to an exported page: a static host answers the page whatever the query. */
    public const QUERY_IGNORED = 'query-ignored';
    /** A link left to the dynamic host (Site::dynamic()). */
    public const DYNAMIC = 'dynamic';
    /** An internal address of a written file that points outside the site's base path (Site::basePath()). */
    public const OUTSIDE_BASE = 'outside-base';
    /** What GitHub Pages will not serve as the export expects (no 404 page, a file or a site above its limits). */
    public const GITHUB_PAGES = 'github-pages';

    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $subject,
        public readonly string $message,
    ) {
    }

    public static function error(string $code, string $subject, string $message): self
    {
        return new self(self::ERROR, $code, $subject, $message);
    }

    public static function warning(string $code, string $subject, string $message): self
    {
        return new self(self::WARNING, $code, $subject, $message);
    }

    public static function notice(string $code, string $subject, string $message): self
    {
        return new self(self::NOTICE, $code, $subject, $message);
    }

    public function __toString(): string
    {
        return \sprintf('[%s] %s %s: %s', $this->level, $this->code, $this->subject, $this->message);
    }

    /** @return array{level: string, code: string, subject: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['level' => $this->level, 'code' => $this->code, 'subject' => $this->subject, 'message' => $this->message];
    }
}
