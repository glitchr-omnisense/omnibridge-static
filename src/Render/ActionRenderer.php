<?php

namespace Omnibridge\Static\Render;

use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticRendererInterface;
use Omnibridge\Static\StaticResponse;
use Psr\Container\ContainerInterface;

/**
 * Renders a page by calling the action its route runs, with no host's request
 * handling: the action taken from a PSR-11 container, called with the route's
 * parameters by name (and `$query`, an empty query string), its other
 * parameters taken from the container by their type, or left to their
 * default; what it returns handed to $answer, the application's own way of
 * turning an action's result into a page (omnibase's Base\Screen\Negotiation,
 * a template engine...).
 *
 *     new ActionRenderer($container, function (mixed $result, StaticPage $page) use ($negotiation): StaticResponse {
 *         $answer = $negotiation->answer($result, new Visit($page->path, 'text/html', null, null));
 *
 *         return new StaticResponse($answer->status, $answer->body, $answer->headers['Content-Type'] ?? 'text/html; charset=UTF-8');
 *     });
 *
 * The page names its action (StaticPage::$action, which RouteCollectionProvider
 * fills from the route's _controller). An action that returns null is no such
 * page (404). What a host adds around an action - its layout listeners, its
 * request-scoped state - is not there: the action's result alone is rendered.
 * RequestRenderer renders the page as the host serves it.
 */
final class ActionRenderer implements StaticRendererInterface
{
    /** @param \Closure(mixed, StaticPage): (StaticResponse|string|null) $answer */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly \Closure $answer,
    ) {
    }

    public function render(StaticPage $page): StaticResponse
    {
        if (null === $page->action) {
            throw new \InvalidArgumentException(\sprintf('The page %s names no action to call.', $page->describe()));
        }
        [$class, $method] = explode('::', $page->action, 2) + [1 => '__invoke'];
        $action = $this->container->has($class) ? $this->container->get($class) : null;
        if (!\is_object($action) || !method_exists($action, $method)) {
            throw new \InvalidArgumentException(\sprintf('The action "%s" of %s is not in the container.', $page->action, $page->describe()));
        }

        $arguments = [];
        foreach ((new \ReflectionMethod($action, $method))->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (\array_key_exists($name, $page->parameters)) {
                $arguments[$name] = $page->parameters[$name];
            } elseif ('query' === $name) {
                $arguments[$name] = [];
            } elseif (null !== ($service = $this->service($parameter))) {
                $arguments[$name] = $service;
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[$name] = null;
            } else {
                throw new \InvalidArgumentException(\sprintf('The parameter $%s of "%s" has no value for %s.', $name, $page->action, $page->describe()));
            }
        }

        $result = $action->{$method}(...$arguments);
        if (null === $result) {
            return StaticResponse::notFound();
        }
        $response = ($this->answer)($result, $page);

        return match (true) {
            $response instanceof StaticResponse => $response,
            \is_string($response) => StaticResponse::html($response),
            default => StaticResponse::notFound(),
        };
    }

    private function service(\ReflectionParameter $parameter): ?object
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $id = $type->getName();
        if (!$this->container->has($id)) {
            return null;
        }
        $service = $this->container->get($id);

        return \is_object($service) ? $service : null;
    }
}
