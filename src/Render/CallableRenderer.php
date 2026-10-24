<?php

namespace Omnibridge\Static\Render;

use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticRendererInterface;
use Omnibridge\Static\StaticResponse;

/**
 * A renderer of any callable: a plain PHP application renders its pages its
 * own way (its templates, its data) and answers a StaticResponse - or a
 * string, an HTML page with status 200, or null, no such page (404).
 *
 *     new CallableRenderer(fn (StaticPage $page) => $twig->render('page.html.twig', ['path' => $page->path]));
 */
final class CallableRenderer implements StaticRendererInterface
{
    /** @param \Closure(StaticPage): (StaticResponse|string|null) $render */
    public function __construct(private readonly \Closure $render)
    {
    }

    public function render(StaticPage $page): StaticResponse
    {
        $response = ($this->render)($page);

        return match (true) {
            $response instanceof StaticResponse => $response,
            \is_string($response) => StaticResponse::html($response),
            default => StaticResponse::notFound(),
        };
    }
}
