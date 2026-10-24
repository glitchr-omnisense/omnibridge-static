<?php

namespace Omnibridge\Static\Tests\Fixtures;

use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;

/**
 * A plain PHP application for the tests: a few pages, a redirection, a page
 * for unknown addresses, a picture it serves itself, and a public folder with
 * a Webpack manifest, a style sheet that refers to a font, a favicon.
 */
final class PlainApp
{
    /** @var array<string, string> */
    public const PUBLIC = [
        'build/manifest.json' => '{"build/app.css": "/build/app.3f2a.css"}',
        'build/app.3f2a.css' => 'body { font-family: A } @font-face { font-family: A; src: url(fonts/a.woff2) }',
        'build/fonts/a.woff2' => 'WOFF2',
        'favicon.ico' => 'ICO',
    ];

    public static function publicDir(): string
    {
        $dir = sys_get_temp_dir().'/omnibridge-static-public-'.getmypid();
        foreach (self::PUBLIC as $file => $content) {
            if (!is_dir(\dirname($dir.'/'.$file))) {
                mkdir(\dirname($dir.'/'.$file), 0o777, true);
            }
            file_put_contents($dir.'/'.$file, $content);
        }

        return $dir;
    }

    public static function render(StaticPage $page): StaticResponse
    {
        $layout = static fn (string $body): string => '<!DOCTYPE html><html><head><link rel="stylesheet" href="/build/app.3f2a.css"><link rel="icon" href="/favicon.ico"></head><body>'.$body.'</body></html>';

        return match ($page->path) {
            '/' => StaticResponse::html($layout('<a href="/blog/hello">Hello</a> <a href="blog/hello?page=2">2</a> <a href="https://example.org/login">Sign in</a> <a href="/missing">?</a> <a href="https://elsewhere.example.org/x">out</a> <a href="mailto:a@example.org">mail</a> <img src="/media/photo.jpg" alt=""> <div data-tiles="/tiles/{z}/{x}.png"></div> <img data-src="/media/%7Bid%7D.jpg">')),
            '/blog/hello' => StaticResponse::html($layout('<h1>Bonjour</h1><a href="/en/blog/hello">English</a> <form method="post" action="/newsletter"></form>')),
            '/en/blog/hello' => StaticResponse::html($layout('<h1>Hello</h1><a href="/blog/hello">Français</a> <form method="post" class="comment"></form> <form method="get" action="/en/blog/hello"></form>')),
            '/old' => StaticResponse::redirect('https://example.org/blog/hello'),
            '/media/photo.jpg' => new StaticResponse(200, 'JPEG', 'image/jpeg'),
            default => StaticResponse::notFound($layout('<h1>Not found</h1><a href="/">Home</a>')),
        };
    }

    public static function site(string $publicDir): Site
    {
        return (new Site('https://example.org'))
            ->provide(new PageList([
                new StaticPage('/blog/hello', locale: 'fr', alternates: ['en' => '/en/blog/hello']),
                new StaticPage('/en/blog/hello', locale: 'en', alternates: ['fr' => '/blog/hello']),
                '/',
                '/old',
            ]))
            ->renderWith(new CallableRenderer(self::render(...)))
            ->publicDir($publicDir)
            ->assets($publicDir.'/build/manifest.json')
            ->copy('favicon.ico')
            ->notFound('/404')
            ->dynamic(['/login', '/newsletter'], 'https://app.example.org');
    }
}
