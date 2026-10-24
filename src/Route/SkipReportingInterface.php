<?php

namespace Omnibridge\Static\Route;

/**
 * A provider that leaves routes out says which and why (a route of a
 * method other than GET, a parameter nobody gave values for...): the
 * exporter lists them in its diagnostics, so that nothing is left out
 * silently.
 */
interface SkipReportingInterface
{
    /**
     * What the last pages() left out.
     *
     * @return array<string, string> subject (a route's name) => why
     */
    public function skipped(): array;
}
