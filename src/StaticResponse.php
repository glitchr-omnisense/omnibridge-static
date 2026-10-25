<?php

namespace Omnibridge\Static;

/**
 * What a page answered at build time: its status, its type, its body - or,
 * for a redirection, where it sends. Nothing of the request's own (cookies,
 * caching headers) is kept: a static host answers every visitor alike.
 */
final class StaticResponse
{
    /**
     * @param int         $status      the HTTP status
     * @param string      $body        the content, as it is to be written
     * @param string|null $contentType the Content-Type header ("text/html; charset=UTF-8")
     * @param string|null $location    where a redirection sends (its Location header)
     * @param string|null $fragment    a redirection that carries the visitor's own #fragment over: the prefix it
     *                                 gains on the way ("" keeps it as it is; null drops it, as a meta refresh does)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $contentType = null,
        public readonly ?string $location = null,
        public readonly ?string $fragment = null,
    ) {
    }

    /**
     * The header a host's response names the fragment's prefix with, for RequestRenderer: a page of a
     * manual merged into one page answers `Location: /docs/flowjs#sticky` and
     * `X-Static-Fragment: sticky-` - /docs/flowjs/sticky#keys then lands on /docs/flowjs#sticky-keys.
     */
    public const FRAGMENT_HEADER = 'X-Static-Fragment';

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, 'text/html; charset=UTF-8');
    }

    public static function json(string $body, int $status = 200): self
    {
        return new self($status, $body, 'application/json');
    }

    /**
     * @param string|null $fragment the visitor's #fragment carried over with this prefix ("" as it is); null: dropped
     */
    public static function redirect(string $location, int $status = 301, ?string $fragment = null): self
    {
        return new self($status, '', null, $location, $fragment);
    }

    public static function notFound(string $body = ''): self
    {
        return new self(404, $body, 'text/html; charset=UTF-8');
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && null !== $this->location && '' !== $this->location;
    }

    /** The media type alone, lower-cased ("text/html"), or null when none was given. */
    public function mediaType(): ?string
    {
        if (null === $this->contentType || '' === trim($this->contentType)) {
            return null;
        }

        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }

    public function isHtml(): bool
    {
        return \in_array($this->mediaType(), ['text/html', 'application/xhtml+xml'], true);
    }

    public function isCss(): bool
    {
        return 'text/css' === $this->mediaType();
    }

    /** The same response with another body (a normaliser's, a rewritten link's). */
    public function withBody(string $body): self
    {
        return new self($this->status, $body, $this->contentType, $this->location);
    }
}
