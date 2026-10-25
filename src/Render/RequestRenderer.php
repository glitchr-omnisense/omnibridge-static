<?php

namespace Omnibridge\Static\Render;

use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticRendererInterface;
use Omnibridge\Static\StaticResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders a page the way the host serves it: a GET request of its address
 * handled in this process by the host's own request handling - the route
 * matched, the same action run, the same templates rendered, the host's
 * listeners on the way - with symfony/http-foundation's Request and Response,
 * which Symfony's kernel and Laravel's both speak (a Laravel request extends
 * it).
 *
 *     // Symfony
 *     new RequestRenderer(fn (Request $request) => $kernel->handle($request), 'https://example.org');
 *     // Laravel
 *     new RequestRenderer(fn (Request $request) => $app->make(Kernel::class)->handle(\Illuminate\Http\Request::createFromBase($request)), 'https://example.org');
 *
 * The request carries no cookie and no session: the page is rendered for a
 * visitor. The host's terminate step is not run (a host that counts a page
 * view there counts none for the export).
 */
final class RequestRenderer implements StaticRendererInterface
{
    public const USER_AGENT = 'omnibridge-static';

    /**
     * @param \Closure(Request): Response $handle  the host's handling of one request
     * @param string                      $baseUrl the public origin the pages are rendered for ("https://example.org"): the host, scheme and port the application sees
     * @param array<string, string>       $headers the headers every request carries (Accept-Language...), beside a page's own
     */
    public function __construct(
        private readonly \Closure $handle,
        private readonly string $baseUrl,
        private readonly array $headers = [],
    ) {
        if (!class_exists(Request::class)) {
            throw new \LogicException('RequestRenderer renders through symfony/http-foundation: composer require symfony/http-foundation.');
        }
    }

    public function render(StaticPage $page): StaticResponse
    {
        $request = Request::create(rtrim($this->baseUrl, '/').$page->path, 'GET');
        $request->headers->set('Accept', StaticPage::RESOURCE === $page->role ? '*/*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $request->headers->set('User-Agent', self::USER_AGENT);
        foreach ($this->headers + $page->headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = ($this->handle)($request);

        return new StaticResponse(
            $response->getStatusCode(),
            self::body($response),
            $response->headers->get('Content-Type'),
            $response->headers->get('Location'),
            $response->headers->get(StaticResponse::FRAGMENT_HEADER),
        );
    }

    private static function body(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            $body = @file_get_contents($response->getFile()->getPathname());

            return false === $body ? '' : $body;
        }
        if ($response instanceof StreamedResponse) {
            ob_start();
            try {
                $response->sendContent();
            } finally {
                $body = (string) ob_get_clean();
            }

            return $body;
        }

        return (string) $response->getContent();
    }
}
