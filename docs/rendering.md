---
title: Rendering
order: 25
---

# Rendering

With the application's own code. A renderer implements `StaticRendererInterface::render(StaticPage):
StaticResponse` and answers as a visitor does: no session, no cookie, nothing a signed-in visitor
would see. A renderer that throws is a `render-failed` error for that page; the build goes on.

## RequestRenderer

A GET request of the address handled in this process by the host - the route matched, the same action
run, the same templates rendered, the host's listeners on the way. Symfony's kernel and Laravel's both
speak symfony/http-foundation:

```php
new RequestRenderer(fn (Request $request) => $kernel->handle($request), 'https://example.org');
new RequestRenderer(fn (Request $request) => $app->make(Kernel::class)->handle(\Illuminate\Http\Request::createFromBase($request)), 'https://example.org');
```

| Argument | Effect |
|---|---|
| `handle` | `\Closure(Request): Response`, the host's handling of one request |
| `baseUrl` | the origin the pages are rendered for: the scheme, host and port the application sees |
| `headers` | headers every request carries (`Accept-Language`...), beside each page's own (`StaticPage::$headers`); where both name a header, the renderer's is kept (`$this->headers + $page->headers`) |

Each request: `GET <baseUrl><path>`, `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8`
(`*/*` for a resource the links led to), `User-Agent: omnibridge-static` (`RequestRenderer::USER_AGENT`).
The response's status, body (a `BinaryFileResponse`'s file read, a `StreamedResponse` captured),
`Content-Type`, `Location` and `X-Static-Fragment` make the `StaticResponse`; nothing else is kept.
No cookie, no session: every page is a visitor's. The host's terminate step is not run (a host that
counts page views there counts none). For a site under a folder of its origin (GitHub Pages), give the
origin, not the folder: `(new Site($url))->origin()`.

## ActionRenderer

The route's action called directly from a PSR-11 container (its route parameters by name, an empty
`$query`, its services by type), its result turned into a page by the application's own answer
(omnibase's `Base\Screen\Negotiation`, a template engine). Nothing of the host around the action: use
it where the action's result is the whole page.

```php
new ActionRenderer($container, function (mixed $result, StaticPage $page) use ($negotiation): StaticResponse {
    $answer = $negotiation->answer($result, new Visit($page->path, 'text/html', null, null));

    return new StaticResponse($answer->status, $answer->body, $answer->headers['Content-Type'] ?? 'text/html; charset=UTF-8');
});
```

- The page names its action (`StaticPage::$action`, which `RouteCollectionProvider` fills from
  `_controller`): `Class` (invokable) or `Class::method`. None, or an action not in the container, is an
  `InvalidArgumentException` (a `render-failed` error).
- Each parameter: the route parameter of that name; `$query` an empty array; else a service of the
  container by its class type; else its default; else null when it allows it; else an
  `InvalidArgumentException`.
- An action that returns null is no such page (404). The answer closure returns a `StaticResponse`, a
  string (HTML, 200) or null (404).

## CallableRenderer

```php
new CallableRenderer(fn (StaticPage $page) => $twig->render('page.html.twig', ['path' => $page->path]));
```

Any closure: a `StaticResponse`, a string (an HTML page, status 200), or null (404).

## One process

One process renders every page, as a long-lived worker (FrankenPHP's worker mode, a queue consumer)
would serve them. A service that keeps what one request left it shows it on the next page; `--verify`
(each page rendered twice) and a page-by-page comparison with the dynamic site find these.
