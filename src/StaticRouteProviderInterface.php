<?php

namespace Omnibridge\Static;

/**
 * Where the addresses to export come from: an explicit list
 * (Route\PageList), a router's routes with the values of their parameters
 * (Route\RouteCollectionProvider), the application's own sitemap
 * (Route\SitemapProvider), or the application's own class.
 *
 * The order the pages come in is kept in the diagnostics; the files written
 * do not depend on it.
 */
interface StaticRouteProviderInterface
{
    /** @return iterable<StaticPage> */
    public function pages(): iterable;
}
