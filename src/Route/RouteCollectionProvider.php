<?php

namespace Omnibridge\Static\Route;

use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticRouteProviderInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The pages of a router's routes (symfony/routing's RouteCollection - what
 * Symfony's router holds, what omnibridge/laravel's Routing\ActionRoutes
 * reads from an application's #[Route] actions, what symfony/routing's
 * attribute loader gives a plain PHP application).
 *
 * A route without a variable is one page. A route with variables is exported
 * for the values a source gives, by the route's name (or, for a localized
 * route such as "blog.fr", by its canonical name "blog"); without one it is
 * left out and said so (skipped()): a parameter has no values the exporter
 * could guess.
 *
 *     new RouteCollectionProvider($router->getRouteCollection(),
 *         parameters: ['article' => fn () => [['slug' => 'hello'], ['slug' => 'world']]],
 *         include: ['app_*'],
 *         exclude: ['app_*_comments'],
 *     );
 *
 * Only the routes that answer GET are read. A localized route's language is
 * the page's locale, and the same parameters under the route's other
 * languages its alternates (sitemap.xml lists them). The path is generated
 * by symfony/routing's UrlGenerator: requirements are checked, a parameter
 * the path does not name is not added as a query string.
 */
final class RouteCollectionProvider implements StaticRouteProviderInterface, SkipReportingInterface
{
    /** @var array<string, string> */
    private array $skipped = [];

    /**
     * @param array<string, iterable<array<string, mixed>>|\Closure(): iterable<array<string, mixed>>> $parameters route name (or canonical name) => the parameter sets
     * @param list<string>|null                                                                        $include    the routes to read, by name (fnmatch patterns); null for all
     * @param list<string>                                                                             $exclude    the routes to leave out, by name (fnmatch patterns)
     * @param list<string>                                                                             $actions    the actions to read, by class prefix ("App\Action\"); empty for all
     * @param list<string>                                                                             $noSitemap  the routes whose pages sitemap.xml does not list (fnmatch patterns)
     */
    public function __construct(
        private readonly RouteCollection $routes,
        private readonly array $parameters = [],
        private readonly ?array $include = null,
        private readonly array $exclude = [],
        private readonly array $actions = [],
        private readonly array $noSitemap = [],
    ) {
    }

    public function pages(): iterable
    {
        $this->skipped = [];
        $generator = new UrlGenerator($this->routes, new RequestContext());
        $generator->setStrictRequirements(true);

        /** @var list<StaticPage> $pages */
        $pages = [];
        /** @var array<string, array<string, string>> $groups canonical route + parameters => locale => path */
        $groups = [];
        foreach ($this->routes->all() as $name => $route) {
            if (!$this->selected($name, $route)) {
                continue;
            }
            $canonical = self::canonical($name, $route);
            /** @var list<string> $variables */
            $variables = $route->compile()->getPathVariables();
            $sets = [[]];
            if ($variables) {
                $source = $this->parameters[$name] ?? $this->parameters[$canonical] ?? null;
                if (null === $source) {
                    $this->skipped[$name] = \sprintf('its path %s has variables (%s) and no source gives their values', $route->getPath(), implode(', ', $variables));
                    continue;
                }
                $sets = $source instanceof \Closure ? $source() : $source;
            }

            $locale = $route->getDefault('_locale');
            $locale = \is_string($locale) ? $locale : null;
            $action = self::action($route);
            foreach ($sets as $set) {
                $parameters = array_intersect_key($set, array_flip($variables));
                try {
                    $url = $generator->generate($name, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
                } catch (RoutingException $e) {
                    $this->skipped[$name.' '.self::key($parameters)] = 'not generated: '.$e->getMessage();
                    continue;
                }
                $path = (string) parse_url($url, \PHP_URL_PATH);
                $page = new StaticPage($path, $name, $parameters, $action, $locale, [], [], !$this->matches($name, $this->noSitemap));
                $pages[] = $page;
                if (null !== $locale) {
                    $groups[$canonical.' '.self::key($parameters)][$locale] = $path;
                }
            }
        }

        foreach ($pages as $page) {
            if (null === $page->locale || null === $page->route) {
                yield $page;
                continue;
            }
            $alternates = $groups[self::canonical($page->route, $this->routes->get($page->route)).' '.self::key($page->parameters)] ?? [];
            unset($alternates[$page->locale]);
            ksort($alternates);
            yield $alternates ? $page->withAlternates($alternates) : $page;
        }
    }

    public function skipped(): array
    {
        return $this->skipped;
    }

    private function selected(string $name, Route $route): bool
    {
        if (null !== $this->include && !$this->matches($name, $this->include)) {
            return false;
        }
        if ($this->matches($name, $this->exclude)) {
            return false;
        }
        if ($this->actions) {
            $action = self::action($route) ?? '';
            $read = false;
            foreach ($this->actions as $prefix) {
                $read = $read || str_starts_with($action, $prefix);
            }
            if (!$read) {
                return false;
            }
        }
        $methods = $route->getMethods();
        if ($methods && !\in_array('GET', $methods, true)) {
            $this->skipped[$name] = \sprintf('it answers %s, not GET', implode(', ', $methods));

            return false;
        }

        return true;
    }

    /** @param list<string> $patterns */
    private function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function canonical(string $name, ?Route $route): string
    {
        $canonical = $route?->getDefault('_canonical_route');

        return \is_string($canonical) && '' !== $canonical ? $canonical : $name;
    }

    /** The action a route runs, as Symfony names it ("Class" or "Class::method"). */
    private static function action(Route $route): ?string
    {
        $controller = $route->getDefault('_controller');
        if (\is_array($controller) && \is_string($controller[0] ?? null) && \is_string($controller[1] ?? null)) {
            return $controller[0].'::'.$controller[1];
        }

        return \is_string($controller) ? $controller : null;
    }

    /** @param array<string, mixed> $parameters */
    private static function key(array $parameters): string
    {
        ksort($parameters);

        return (string) json_encode($parameters, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
