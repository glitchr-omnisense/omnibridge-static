<?php

namespace Omnibridge\Static\Tests\Render;

use Omnibridge\Static\Render\RequestRenderer;
use Omnibridge\Static\StaticPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RequestRendererTest extends TestCase
{
    public function testThePageIsAVisitorsGetRequestOfItsAddressOnTheBaseUrl(): void
    {
        $seen = null;
        $renderer = new RequestRenderer(static function (Request $request) use (&$seen): Response {
            $seen = $request;

            return new Response('<p>'.$request->getPathInfo().'</p>', 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Set-Cookie' => 'a=b']);
        }, 'https://example.org:8443', ['Accept-Language' => 'fr']);

        $response = $renderer->render(new StaticPage('/japon/articles', headers: ['X-Test' => 'yes']));

        self::assertInstanceOf(Request::class, $seen);
        self::assertSame('GET', $seen->getMethod());
        self::assertSame('https://example.org:8443/japon/articles', $seen->getUri());
        self::assertTrue($seen->isSecure());
        self::assertSame([], $seen->cookies->all(), 'no cookie: a visitor');
        self::assertSame(RequestRenderer::USER_AGENT, $seen->headers->get('User-Agent'));
        self::assertSame('fr', $seen->headers->get('Accept-Language'));
        self::assertSame('yes', $seen->headers->get('X-Test'));
        self::assertStringStartsWith('text/html', (string) $seen->headers->get('Accept'));
        self::assertSame(200, $response->status);
        self::assertSame('<p>/japon/articles</p>', $response->body);
        self::assertTrue($response->isHtml());
    }

    public function testARedirectionAFileAndAStream(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'static');
        file_put_contents($file, 'PNG');
        try {
            $renderer = new RequestRenderer(static fn (Request $request): Response => match ($request->getPathInfo()) {
                '/old' => new RedirectResponse('/new', 301),
                '/image.png' => new BinaryFileResponse($file, 200, ['Content-Type' => 'image/png']),
                default => new StreamedResponse(static function (): void { echo 'streamed'; }, 200, ['Content-Type' => 'text/plain']),
            }, 'http://localhost');

            $redirect = $renderer->render(new StaticPage('/old'));
            self::assertTrue($redirect->isRedirect());
            self::assertSame('/new', $redirect->location);
            self::assertSame('PNG', $renderer->render(new StaticPage('/image.png', role: StaticPage::RESOURCE))->body);
            self::assertSame('streamed', $renderer->render(new StaticPage('/stream.txt'))->body);
        } finally {
            unlink($file);
        }
    }
}
