<?php

namespace Omnibridge\Static;

/**
 * Renders one page at build time, by the application's own code: through its
 * host's request handling (Render\RequestRenderer: Symfony's kernel, Laravel's),
 * by calling the route's action itself (Render\ActionRenderer), or by any
 * callable (Render\CallableRenderer, a plain PHP application).
 *
 * A renderer answers as a visitor does: no session, no cookie, nothing a
 * signed-in visitor would see.
 */
interface StaticRendererInterface
{
    public function render(StaticPage $page): StaticResponse;
}
