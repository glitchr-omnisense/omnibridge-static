<?php

/*
 * A plain PHP site exported by omnibridge/static - no framework, no bundler:
 *
 *     php ../../bin/omnibridge-static build site.php --out=dist
 *     php ../../bin/omnibridge-static check dist
 *
 * Its pages are PHP templates, its posts a PHP array (any source would do: a
 * database, Markdown files); its style sheet is listed in a Webpack-style
 * manifest. The package's CI builds it and checks the export.
 */

use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;

$posts = [
    'hello' => ['title' => 'Hello', 'body' => 'The first post.'],
    'static' => ['title' => 'Static', 'body' => 'Rendered once, served by any static host.'],
];

$view = static function (string $template, array $data): string {
    extract($data);
    ob_start();
    require __DIR__.'/templates/'.$template.'.php';

    return (string) ob_get_clean();
};

$render = static function (StaticPage $page) use ($posts, $view): ?StaticResponse {
    if ('/' === $page->path) {
        return StaticResponse::html($view('home', ['posts' => $posts]));
    }
    if ('/old-blog' === $page->path) {
        return StaticResponse::redirect('/');
    }
    if (preg_match('~^/posts/([a-z-]+)$~', $page->path, $match) && isset($posts[$match[1]])) {
        return StaticResponse::html($view('post', ['post' => $posts[$match[1]]]));
    }

    return StaticResponse::notFound($view('not-found', []));
};

// STATIC_ORIGIN: its public address, an origin or a folder of one (https://owner.github.io/example/).
return (new Site(getenv('STATIC_ORIGIN') ?: 'https://example.org'))
    ->provide(new PageList(['/', '/old-blog', ...array_map(static fn (string $slug) => '/posts/'.$slug, array_keys($posts))]))
    ->renderWith(new CallableRenderer($render))
    ->publicDir(__DIR__.'/public')
    ->assets(__DIR__.'/public/build/manifest.json')
    ->copy('robots.txt')
    ->notFound('/404');
