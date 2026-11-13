<?php

namespace Omnibridge\Static\Build;

use Omnibridge\Static\StaticResponse;

/**
 * The answers of the renderer kept between exports, one file per page
 * fingerprint (<fingerprint>.response: a line of JSON - status, type,
 * location, fragment - then the body as it came). An export reads the
 * answer of a page whose fingerprint it has instead of rendering it, and
 * everything after the rendering (normalisers, links, base path, sitemap)
 * runs on it as on a fresh one: the files written are the same.
 *
 * prune() removes the entries this export did not use; nothing else in the
 * folder is touched.
 */
final class ResponseCache
{
    private const SUFFIX = '.response';

    /** @var array<string, true> */
    private array $used = [];

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(\sprintf('The cache folder "%s" cannot be made.', $this->dir));
        }
    }

    public function get(string $fingerprint): ?StaticResponse
    {
        $data = @file_get_contents($this->file($fingerprint));
        if (false === $data || false === $newline = strpos($data, "\n")) {
            return null;
        }
        try {
            $head = json_decode(substr($data, 0, $newline), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($head) || !\is_int($head['status'] ?? null)) {
            return null;
        }
        $string = static fn (mixed $value): ?string => \is_string($value) ? $value : null;
        $this->used[$fingerprint] = true;

        return new StaticResponse($head['status'], substr($data, $newline + 1), $string($head['contentType'] ?? null), $string($head['location'] ?? null), $string($head['fragment'] ?? null));
    }

    public function put(string $fingerprint, StaticResponse $response): void
    {
        $head = json_encode(['status' => $response->status, 'contentType' => $response->contentType, 'location' => $response->location, 'fragment' => $response->fragment], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        // Written aside, then renamed: an entry is whole or absent.
        $temporary = $this->file($fingerprint).'.'.getmypid().'.tmp';
        if (false !== file_put_contents($temporary, $head."\n".$response->body)) {
            rename($temporary, $this->file($fingerprint));
            $this->used[$fingerprint] = true;
        }
    }

    /** Removes the entries this export neither read nor wrote; returns how many. */
    public function prune(): int
    {
        $removed = 0;
        foreach ((array) scandir($this->dir) as $entry) {
            if (\is_string($entry) && preg_match('~^([0-9a-f]{64})\.response(\.\d+\.tmp)?$~', $entry, $match) && (isset($match[2]) || !isset($this->used[$match[1]]))) {
                unlink($this->dir.'/'.$entry);
                ++$removed;
            }
        }

        return $removed;
    }

    private function file(string $fingerprint): string
    {
        return $this->dir.'/'.$fingerprint.self::SUFFIX;
    }
}
