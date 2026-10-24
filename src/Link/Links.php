<?php

namespace Omnibridge\Static\Link;

/**
 * The addresses a page or a style sheet refers to, and the rewriting of some
 * of them: what the exporter follows to check that every internal link
 * resolves within the export, to collect the files the pages need, and to
 * send a visitor to the dynamic host for what cannot be static.
 *
 * Read from the markup's tags (not from scripts nor comments): a link's href
 * (PAGE), a form's action (FORM, or POST for a form posted; "" for a form
 * without one, posted to the document's own address), a src, a srcset, a poster
 * (and the data-src, data-srcset, data-poster of the lazy loaders), a <link>'s href,
 * a url() of a style attribute or a <style>, an SVG's <use href> and <image href>
 * (RESOURCE), a <meta http-equiv="refresh">'s address (PAGE); from a style sheet,
 * its url() and @import. rewriteAddresses() and rewriteCss() rewrite the same
 * addresses (Output\BasePath moves them under a site's base path).
 */
final class Links
{
    public const PAGE = 'page';
    public const FORM = 'form';
    /** A form posted (method="post"): a static host takes no post. */
    public const POST = 'post';
    public const RESOURCE = 'resource';

    // Possessive: an attribute may be a megabyte long (an inline data: picture), and backtracking over it
    // would exhaust PCRE's limit. A self-closing slash is part of the attributes, which ignore it.
    private const TAG = '~<([a-zA-Z][a-zA-Z0-9-]*)(\s[^>]*+)?>~';
    private const ATTRIBUTE = '~(?<=\s)([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)~';
    private const SVG_REFERENCES = ['use', 'image', 'feimage'];
    // A refresh's content: its delay, then "url=" and the address, quoted or not.
    private const REFRESH = '~^\s*\d+(?:\.\d*)?\s*[;,]\s*(?:url\s*=\s*)?(["\']?)(.*?)\1\s*$~is';
    private const CSS_URL = '~url\(\s*(["\']?)([^"\')]+)\1\s*\)|@import\s+(["\'])([^"\']+)\3~i';

    /**
     * @param list<string> $resources more attributes that name a file a script loads (a gallery's "data-thumb")
     *
     * @return list<array{string, string}> [kind, address], in the order they come, each address as written (entities decoded)
     */
    public static function html(string $html, array $resources = []): array
    {
        $resources = array_map(strtolower(...), $resources);
        $links = [];
        $html = self::visible($html);
        self::checked(preg_match_all(self::TAG, $html, $tags, \PREG_SET_ORDER));
        foreach ($tags as $tag) {
            $name = strtolower($tag[1]);
            $attributes = self::attributes($tag[2] ?? '');
            if ('form' === $name) {
                $named = array_column($attributes, 1, 0);
                $links[] = ['post' === strtolower(trim($named['method'] ?? 'get')) ? self::POST : self::FORM, trim($named['action'] ?? '')];
                continue;
            }
            if ('meta' === $name) {
                $named = array_column($attributes, 1, 0);
                if ('refresh' === strtolower(trim($named['http-equiv'] ?? '')) && null !== $target = self::refreshTarget($named['content'] ?? '')) {
                    $links[] = [self::PAGE, $target];
                }
                continue;
            }
            foreach ($attributes as [$attribute, $value]) {
                if (\in_array($attribute, $resources, true) && '' !== trim($value)) {
                    $links[] = [self::RESOURCE, trim($value)];
                    continue;
                }
                foreach (self::addresses($name, $attribute, $value) as $link) {
                    $links[] = $link;
                }
            }
        }
        self::checked(preg_match_all('~<style\b[^>]*+>(.*?)</style>~is', $html, $styles));
        foreach ($styles[1] as $style) {
            foreach (self::css($style) as $link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /** @return list<array{string, string}> [kind, address] */
    public static function css(string $css): array
    {
        $links = [];
        $css = self::checked(preg_replace('~/\*.*?\*/~s', '', $css));
        self::checked(preg_match_all(self::CSS_URL, $css, $matches, \PREG_SET_ORDER));
        foreach ($matches as $match) {
            $url = trim('' !== ($match[2] ?? '') ? $match[2] : ($match[4] ?? ''));
            if ('' !== $url) {
                $links[] = [self::RESOURCE, $url];
            }
        }

        return $links;
    }

    /**
     * The markup with the PAGE and FORM addresses $rewrite changes rewritten: it is given the address as
     * written (a form without an action: "", the document's own address), the kind and the tag's
     * attributes, and returns the new address, or null to leave it. Scripts and comments are left
     * untouched.
     *
     * @param \Closure(string $address, string $kind, array<string, string> $attributes): ?string $rewrite
     */
    public static function rewrite(string $html, \Closure $rewrite): string
    {
        $parts = self::checked(preg_split('~(<script\b.*?</script>|<!--.*?-->)~is', $html, -1, \PREG_SPLIT_DELIM_CAPTURE));
        foreach ($parts as $index => $part) {
            if (1 === $index % 2) {
                continue;
            }
            $parts[$index] = self::checked(preg_replace_callback(self::TAG, static function (array $tag) use ($rewrite): string {
                $kind = match (strtolower($tag[1])) {
                    'a', 'area' => self::PAGE,
                    'form' => self::FORM,
                    default => null,
                };
                if (null === $kind) {
                    return $tag[0];
                }
                $source = $tag[2] ?? '';
                $attributes = [];
                foreach (self::attributes($source) as [$name, $value]) {
                    $attributes[$name] ??= $value;
                }
                $attribute = self::FORM === $kind ? 'action' : 'href';
                if (!isset($attributes[$attribute])) {
                    $new = self::FORM === $kind ? $rewrite('', $kind, $attributes) : null;

                    return null === $new ? $tag[0] : '<'.$tag[1].$source.' action="'.htmlspecialchars($new, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"'.substr($tag[0], \strlen('<'.$tag[1].$source));
                }
                $done = false;
                $rewritten = self::checked(preg_replace_callback(self::ATTRIBUTE, static function (array $match) use ($attribute, $attributes, $kind, $rewrite, &$done): string {
                    if ($done || strtolower($match[1]) !== $attribute) {
                        return $match[0];
                    }
                    $done = true;
                    $new = $rewrite($attributes[$attribute], $kind, $attributes);
                    if (null === $new || $new === $attributes[$attribute]) {
                        return $match[0];
                    }

                    return $match[1].'="'.htmlspecialchars($new, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"';
                }, $source));

                return '<'.$tag[1].$rewritten.substr($tag[0], \strlen('<'.$tag[1].$source));
            }, $part));
        }

        return implode('', $parts);
    }

    /**
     * The markup with every address html() reads that $rewrite changes rewritten (with a script's src, a
     * <style>'s url()s, each candidate of a srcset, a refresh's address): it is given the address as written
     * (entities decoded) and its kind, and returns the new address or null to leave it. Scripts' bodies and
     * comments are left untouched, and so is every attribute whose address does not change.
     *
     * @param \Closure(string $address, string $kind): ?string $rewrite
     * @param list<string>                                    $resources more attributes that name a file (Site::resourceAttributes())
     */
    public static function rewriteAddresses(string $html, \Closure $rewrite, array $resources = []): string
    {
        $resources = array_map(strtolower(...), $resources);

        return self::checked(preg_replace_callback('~<!--.*?-->|(<script\b[^>]*+>)(.*?)(?=</script>)|(<style\b[^>]*+>)(.*?)(?=</style>)|'.substr(self::TAG, 1, -1).'~is', static function (array $match) use ($rewrite, $resources): string {
            if (str_starts_with($match[0], '<!--')) {
                return $match[0];
            }
            if ('' !== ($match[1] ?? '')) {
                // A script's own tag (its src), not its body.
                return self::rewriteTag($match[1], $rewrite, $resources).($match[2] ?? '');
            }
            if ('' !== ($match[3] ?? '')) {
                return self::rewriteTag($match[3], $rewrite, $resources).self::rewriteCss($match[4] ?? '', static fn (string $address): ?string => $rewrite($address, self::RESOURCE));
            }

            return self::rewriteTag($match[0], $rewrite, $resources);
        }, $html));
    }

    /**
     * A style sheet with the addresses of its url()s and @imports that $rewrite changes rewritten; its
     * comments left untouched.
     *
     * @param \Closure(string $address): ?string $rewrite
     */
    public static function rewriteCss(string $css, \Closure $rewrite): string
    {
        return self::checked(preg_replace_callback('~/\*.*?\*/|'.substr(self::CSS_URL, 1, -2).'~is', static function (array $match) use ($rewrite): string {
            if (str_starts_with($match[0], '/*')) {
                return $match[0];
            }
            $address = '' !== ($match[2] ?? '') ? $match[2] : ($match[4] ?? '');
            $new = '' !== trim($address) ? $rewrite(trim($address)) : null;
            if (null === $new || $new === trim($address)) {
                return $match[0];
            }

            return str_replace($address, $new, $match[0]);
        }, $css));
    }

    /**
     * One tag with the addresses of its attributes rewritten.
     *
     * @param \Closure(string, string): ?string $rewrite
     * @param list<string>                     $resources
     */
    private static function rewriteTag(string $tag, \Closure $rewrite, array $resources): string
    {
        if (!preg_match(self::TAG, $tag, $parts) || '' === ($parts[2] ?? '')) {
            return $tag;
        }
        $name = strtolower($parts[1]);
        $named = array_column(self::attributes($parts[2]), 1, 0);
        $source = self::checked(preg_replace_callback(self::ATTRIBUTE, static function (array $match) use ($name, $named, $rewrite, $resources): string {
            $attribute = strtolower($match[1]);
            $raw = $match[2];
            $value = html_entity_decode(\in_array($raw[0], ['"', "'"], true) ? substr($raw, 1, -1) : $raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $one = static function (string $kind) use ($value, $rewrite): string {
                $address = trim($value);

                $new = '' === $address ? null : $rewrite($address, $kind);

                return null === $new || $new === $address ? $value : $new;
            };
            $new = match (true) {
                'form' === $name && 'action' === $attribute => $one('post' === strtolower(trim($named['method'] ?? 'get')) ? self::POST : self::FORM),
                'meta' === $name && 'content' === $attribute && 'refresh' === strtolower(trim($named['http-equiv'] ?? '')) => self::rewriteRefresh($value, $rewrite),
                \in_array($attribute, $resources, true) => $one(self::RESOURCE),
                'href' === $attribute && \in_array($name, ['a', 'area'], true) => $one(self::PAGE),
                'href' === $attribute && 'link' === $name => $one(self::RESOURCE),
                \in_array($attribute, ['href', 'xlink:href'], true) && \in_array($name, self::SVG_REFERENCES, true) => $one(self::RESOURCE),
                \in_array($attribute, ['src', 'poster', 'data-src', 'data-poster'], true) => $one(self::RESOURCE),
                \in_array($attribute, ['srcset', 'imagesrcset', 'data-srcset'], true) => self::rewriteSrcset($value, $rewrite),
                'style' === $attribute => self::rewriteCss($value, static fn (string $address): ?string => $rewrite($address, self::RESOURCE)),
                default => $value,
            };
            if ($new === $value) {
                return $match[0];
            }

            return $match[1].'="'.htmlspecialchars($new, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"';
        }, $parts[2]));

        return '<'.$parts[1].$source.substr($tag, \strlen('<'.$parts[1].$parts[2]));
    }

    /** @param \Closure(string, string): ?string $rewrite */
    private static function rewriteSrcset(string $value, \Closure $rewrite): string
    {
        $new = '';
        $from = 0;
        foreach (self::srcsetCandidates($value) as [$position, $url]) {
            $new .= substr($value, $from, $position - $from).($rewrite($url, self::RESOURCE) ?? $url);
            $from = $position + \strlen($url);
        }

        return $new.substr($value, $from);
    }

    /** @param \Closure(string, string): ?string $rewrite */
    private static function rewriteRefresh(string $content, \Closure $rewrite): string
    {
        if (!preg_match(self::REFRESH, $content, $match, \PREG_OFFSET_CAPTURE) || '' === trim($match[2][0])) {
            return $content;
        }
        $new = $rewrite(trim($match[2][0]), self::PAGE);

        return null === $new ? $content : substr($content, 0, $match[2][1]).$new.substr($content, $match[2][1] + \strlen($match[2][0]));
    }

    private static function refreshTarget(string $content): ?string
    {
        return preg_match(self::REFRESH, $content, $match) && '' !== trim($match[2]) ? trim($match[2]) : null;
    }

    /** The markup without its scripts' bodies and its comments. */
    private static function visible(string $html): string
    {
        return self::checked(preg_replace(['~(<script\b[^>]*+>).*?</script>~is', '~<!--.*?-->~s'], ['$1</script>', ''], $html));
    }

    /** @return list<array{string, string}> [name, value decoded] */
    private static function attributes(string $source): array
    {
        $attributes = [];
        self::checked(preg_match_all(self::ATTRIBUTE, ' '.$source, $matches, \PREG_SET_ORDER));
        foreach ($matches as $match) {
            $value = $match[2];
            if (\in_array($value[0], ['"', "'"], true)) {
                $value = substr($value, 1, -1);
            }
            $attributes[] = [strtolower($match[1]), html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')];
        }

        return $attributes;
    }

    /** @return list<array{string, string}> */
    private static function addresses(string $tag, string $attribute, string $value): array
    {
        $value = trim($value);
        if ('' === $value) {
            return [];
        }

        return match (true) {
            'href' === $attribute && \in_array($tag, ['a', 'area'], true) => [[self::PAGE, $value]],
            'href' === $attribute && 'link' === $tag => [[self::RESOURCE, $value]],
            // An SVG's <use href="/sprite.svg#icon">, <image href>: a file the picture draws.
            \in_array($attribute, ['href', 'xlink:href'], true) && \in_array($tag, self::SVG_REFERENCES, true) => [[self::RESOURCE, $value]],
            // data-src, data-srcset, data-poster: the lazy loaders' (lazysizes, vanilla-lazyload...), which set the real one.
            \in_array($attribute, ['src', 'poster', 'data-src', 'data-poster'], true) => [[self::RESOURCE, $value]],
            \in_array($attribute, ['srcset', 'imagesrcset', 'data-srcset'], true) => self::srcset($value),
            'style' === $attribute => self::css($value),
            default => [],
        };
    }

    /** @return list<array{string, string}> */
    private static function srcset(string $value): array
    {
        return array_map(static fn (array $candidate): array => [self::RESOURCE, $candidate[1]], self::srcsetCandidates($value));
    }

    /** @return list<array{int, string}> each candidate's address and where it starts */
    private static function srcsetCandidates(string $value): array
    {
        // The HTML standard's reading of a srcset: an address up to a space (a trailing comma ends the
        // candidate), then its descriptors up to a comma - an address may itself hold commas.
        $candidates = [];
        $length = \strlen($value);
        $position = 0;
        while ($position < $length) {
            $position += strspn($value, " \t\n\r\f,", $position);
            if ($position >= $length) {
                break;
            }
            $start = $position;
            $url = substr($value, $position, strcspn($value, " \t\n\r\f", $position));
            $position += \strlen($url);
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $position += strcspn($value, ',', $position);
            }
            if ('' !== $url) {
                $candidates[] = [$start, $url];
            }
        }

        return $candidates;
    }

    /**
     * A PCRE result, or an exception: a pattern that fails (a backtracking limit on a page of several
     * megabytes) must not pass for "no match" - a page would be written with a part of it missing.
     *
     * @template T
     *
     * @param T|false|null $result
     *
     * @return T
     */
    private static function checked(mixed $result): mixed
    {
        if (false === $result || null === $result) {
            throw new \RuntimeException('A pattern failed on the page: '.preg_last_error_msg());
        }

        return $result;
    }
}
