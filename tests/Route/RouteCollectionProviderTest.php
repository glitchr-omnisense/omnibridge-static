<?php

namespace Omnibridge\Static\Tests\Route;

use Omnibridge\Static\Route\RouteCollectionProvider;
use Omnibridge\Static\StaticPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteCollectionProviderTest extends TestCase
{
    public function testARouteWithoutVariablesIsOnePage(): void
    {
        $routes = new RouteCollection();
        $routes->add('home', new Route('/', ['_controller' => 'App\Action\Home']));

        $pages = iterator_to_array((new RouteCollectionProvider($routes))->pages(), false);

        self::assertCount(1, $pages);
        self::assertSame('/', $pages[0]->path);
        self::assertSame('home', $pages[0]->route);
        self::assertSame('App\Action\Home', $pages[0]->action);
    }

    public function testAParameterisedRouteIsExportedForTheValuesItsSourceGives(): void
    {
        $routes = new RouteCollection();
        $routes->add('article', new Route('/{destination}/articles/{slug}', ['_controller' => ['App\Action\Article', 'show']], ['slug' => '[a-z-]+']));

        $provider = new RouteCollectionProvider($routes, parameters: [
            'article' => static fn () => [
                ['destination' => 'japon', 'slug' => 'le-fuji', 'title' => 'not in the path'],
                ['destination' => 'usa', 'slug' => 'Not Valid'],
            ],
        ]);
        $pages = iterator_to_array($provider->pages(), false);

        self::assertSame(['/japon/articles/le-fuji'], array_map(static fn (StaticPage $page) => $page->path, $pages), 'a parameter the path does not name is no query string');
        self::assertSame(['destination' => 'japon', 'slug' => 'le-fuji'], $pages[0]->parameters);
        self::assertSame('App\Action\Article::show', $pages[0]->action);
        self::assertCount(1, $provider->skipped(), 'the value its requirement refuses is said');
        self::assertStringContainsString('not generated', (string) current($provider->skipped()));
    }

    public function testARouteWithoutASourceOrWithoutGetIsLeftOutAndSaidSo(): void
    {
        $routes = new RouteCollection();
        $routes->add('article', new Route('/articles/{slug}'));
        $routes->add('subscribe', new Route('/subscribe', methods: ['POST']));
        $routes->add('about', new Route('/about', methods: ['GET', 'HEAD']));

        $provider = new RouteCollectionProvider($routes);
        $pages = iterator_to_array($provider->pages(), false);

        self::assertSame(['/about'], array_map(static fn (StaticPage $page) => $page->path, $pages));
        self::assertSame(['article', 'subscribe'], array_keys($provider->skipped()));
        self::assertStringContainsString('slug', $provider->skipped()['article']);
        self::assertStringContainsString('POST', $provider->skipped()['subscribe']);
    }

    public function testALocalizedRoutesLanguagesAreAlternatesOfOneAnother(): void
    {
        $routes = new RouteCollection();
        foreach (['fr' => '/{destination}/voyages', 'en' => '/{destination}/travels'] as $locale => $path) {
            $routes->add('timeline.'.$locale, new Route($path, ['_locale' => $locale, '_canonical_route' => 'timeline']));
        }

        $pages = iterator_to_array((new RouteCollectionProvider($routes, parameters: ['timeline' => [['destination' => 'japon'], ['destination' => 'usa']]]))->pages(), false);

        self::assertSame(['/japon/voyages', '/usa/voyages', '/japon/travels', '/usa/travels'], array_map(static fn (StaticPage $page) => $page->path, $pages));
        self::assertSame('fr', $pages[0]->locale);
        self::assertSame(['en' => '/japon/travels'], $pages[0]->alternates);
        self::assertSame(['fr' => '/usa/voyages'], $pages[3]->alternates);
    }

    public function testRoutesAreChosenByNameAndByAction(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_home', new Route('/', ['_controller' => 'App\Action\Home']));
        $routes->add('app_feed', new Route('/feed', ['_controller' => 'App\Action\Feed']));
        $routes->add('app_admin', new Route('/admin', ['_controller' => 'App\Controller\Admin']));
        $routes->add('_profiler', new Route('/_profiler', ['_controller' => 'App\Action\Profiler']));

        $provider = new RouteCollectionProvider($routes, include: ['app_*'], exclude: ['app_feed'], actions: ['App\Action\\'], noSitemap: ['app_home']);
        $pages = iterator_to_array($provider->pages(), false);

        self::assertSame(['/'], array_map(static fn (StaticPage $page) => $page->path, $pages));
        self::assertFalse($pages[0]->sitemap);
    }
}
