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
 * a url() of a style attribute or a <style> (RESOURCE); from a style sheet,
 * its url() and @import.
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
        // The HTML standard's reading of a srcset: an address up to a space (a trailing comma ends the
        // candidate), then its descriptors up to a comma - an address may itself hold commas.
        $links = [];
        $length = \strlen($value);
        $position = 0;
        while ($position < $length) {
            $position += strspn($value, " \t\n\r\f,", $position);
            if ($position >= $length) {
                break;
            }
            $url = substr($value, $position, strcspn($value, " \t\n\r\f", $position));
            $position += \strlen($url);
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $position += strcspn($value, ',', $position);
            }
            if ('' !== $url) {
                $links[] = [self::RESOURCE, $url];
            }
        }

        return $links;
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
