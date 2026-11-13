<?php

namespace Omnibridge\Static\Tests\Fixtures;

use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;

/**
 * A site made of files, for the incremental exports: a layout every page is
 * rendered in, one page per file of content/ (its route list follows the
 * folder), a home page that lists them, a redirection, a page for unknown
 * addresses, a picture the application serves, and a public style sheet
 * that refers to a font. Each rendering is counted.
 */
final class ContentSite
{
    /** @var array<string, int> path => how many times it was rendered */
    public static array $renders = [];

    /** A fresh folder: layout.html, content/{a,b,c}.txt, public/style.css and its font. */
    public static function make(string $root): void
    {
        Folder::remove($root);
        foreach ([
            'layout.html' => '<!DOCTYPE html><html><head><link rel="stylesheet" href="/style.css"></head><body>{body}</body></html>',
            'content/a.txt' => 'Alpha',
            'content/b.txt' => 'Beta',
            'content/c.txt' => 'Gamma',
            'public/style.css' => 'body { color: black } @font-face { font-family: F; src: url(font.woff2) }',
            'public/font.woff2' => 'WOFF2',
        ] as $file => $content) {
            self::put($root.'/'.$file, $content);
        }
    }

    public static function put(string $file, string $content): void
    {
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }
        file_put_contents($file, $content);
    }

    /**
     * @param bool $declared whether the site declares its inputs (Site::dependsOn())
     * @param bool $cached   whether it keeps the renderer's answers (Site::cache(), in $root/cache)
     */
    public static function site(string $root, bool $declared = true, bool $cached = true): Site
    {
        $names = array_map(static fn (string $file): string => basename($file, '.txt'), glob($root.'/content/*.txt') ?: []);
        sort($names);
        $layout = static fn (string $body): string => str_replace('{body}', $body, (string) file_get_contents($root.'/layout.html'));

        $render = static function (StaticPage $page) use ($root, $names, $layout): StaticResponse {
            self::$renders[$page->path] = (self::$renders[$page->path] ?? 0) + 1;
            $name = substr($page->path, 1);
            if ('/' === $page->path) {
                $items = array_map(static fn (string $name): string => '<li><a href="/'.$name.'">'.file_get_contents($root.'/content/'.$name.'.txt').'</a></li>', $names);

                return StaticResponse::html($layout('<ul>'.implode('', $items).'</ul><img src="/logo.png" alt="">'));
            }
            if (\in_array($name, $names, true)) {
                return StaticResponse::html($layout('<h1>'.file_get_contents($root.'/content/'.$name.'.txt').'</h1><a href="/">Home</a>'));
            }

            return match ($page->path) {
                '/old' => StaticResponse::redirect('/a'),
                '/logo.png' => new StaticResponse(200, 'PNG', 'image/png'),
                default => StaticResponse::notFound($layout('<h1>Not found</h1>')),
            };
        };

        $site = (new Site('https://example.org'))
            ->provide(new PageList(array_merge(['/', '/old'], array_map(static fn (string $name): string => '/'.$name, $names))))
            ->renderWith(new CallableRenderer($render))
            ->publicDir($root.'/public')
            ->copy('style.css')
            ->notFound('/404');
        if ($declared) {
            $site->dependsOn([$root.'/layout.html'], static fn (StaticPage $page): array => match (true) {
                // The list: every page's title.
                '/' === $page->path => [$root.'/content'],
                is_file($root.'/content'.$page->path.'.txt') => [$root.'/content'.$page->path.'.txt'],
                // The redirection, the page for unknown addresses and the picture: the layout alone.
                default => [],
            });
        }

        return $cached ? $site->cache($root.'/cache') : $site;
    }
}
