<?php

namespace Omnibridge\Static\Tests\Render;

use Omnibridge\Static\Render\ActionRenderer;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ActionRendererTest extends TestCase
{
    public function testTheRoutesActionIsCalledWithItsParametersAndServices(): void
    {
        $renderer = new ActionRenderer(self::container(), static fn (mixed $result, StaticPage $page): StaticResponse => StaticResponse::html(\is_array($result) ? implode('|', array_map(static fn (mixed $each): string => \is_string($each) ? $each : '', $result)) : ''));

        $response = $renderer->render(new StaticPage('/japon/articles/le-fuji', parameters: ['slug' => 'le-fuji', 'destination' => 'japon'], action: ArticleAction::class));

        self::assertSame(200, $response->status);
        self::assertSame('le-fuji|japon|0|Hello', $response->body, 'the route\'s parameters by name, an empty query, a service by its type');
    }

    public function testAnActionThatFindsNothingIsNoSuchPage(): void
    {
        $renderer = new ActionRenderer(self::container(), static fn (): string => 'never');

        self::assertSame(404, $renderer->render(new StaticPage('/x/articles/none', parameters: ['slug' => 'none'], action: ArticleAction::class.'::__invoke'))->status);
    }

    public function testAPageWithoutAnActionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ActionRenderer(self::container(), static fn (): string => ''))->render(new StaticPage('/'));
    }

    private static function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return match ($id) {
                    ArticleAction::class => new ArticleAction(),
                    Greeting::class => new Greeting(),
                    default => throw new \RuntimeException($id),
                };
            }

            public function has(string $id): bool
            {
                return \in_array($id, [ArticleAction::class, Greeting::class], true);
            }
        };
    }
}

final class Greeting
{
    public string $text = 'Hello';
}

final class ArticleAction
{
    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>|null
     */
    public function __invoke(string $slug, Greeting $greeting, ?string $destination = null, array $query = []): ?array
    {
        return 'none' === $slug ? null : [$slug, (string) $destination, (string) \count($query), $greeting->text];
    }
}
