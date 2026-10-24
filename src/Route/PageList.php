<?php

namespace Omnibridge\Static\Route;

use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticRouteProviderInterface;

/**
 * The plainest provider: the addresses written out, as paths or as pages.
 *
 *     new PageList(['/', '/about', '/blog/hello']);
 */
final class PageList implements StaticRouteProviderInterface
{
    /** @param iterable<string|StaticPage>|\Closure(): iterable<string|StaticPage> $pages */
    public function __construct(private readonly iterable|\Closure $pages)
    {
    }

    public function pages(): iterable
    {
        $pages = $this->pages instanceof \Closure ? ($this->pages)() : $this->pages;
        foreach ($pages as $page) {
            yield $page instanceof StaticPage ? $page : new StaticPage($page);
        }
    }
}
