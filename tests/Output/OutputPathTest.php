<?php

namespace Omnibridge\Static\Tests\Output;

use Omnibridge\Static\Output\OutputPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutputPathTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function pages(): iterable
    {
        yield 'the root' => ['/', 'index.html'];
        yield 'a page' => ['/blog/hello', 'blog/hello/index.html'];
        yield 'a trailing slash' => ['/blog/hello/', 'blog/hello/index.html'];
        yield 'an .html page' => ['/about.html', 'about.html'];
        yield 'a dot that is no extension of HTML' => ['/release/2.0', 'release/2.0/index.html'];
        yield 'percent-encoded' => ['/caf%C3%A9', 'café/index.html'];
    }

    #[DataProvider('pages')]
    public function testAPageIsWrittenAsIndexHtmlInItsFolder(string $path, string $file): void
    {
        self::assertSame($file, OutputPath::page($path));
    }

    public function testAFileIsWrittenAtItsPath(): void
    {
        self::assertSame('build/app.3f2a.css', OutputPath::file('/build/app.3f2a.css'));
        self::assertSame('media/a b.png', OutputPath::file('/media/a%20b.png'));
    }

    public function testAnAddressThatLeavesTheExportIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutputPath::file('/a/%2e%2e/secret');
    }

    public function testAFolderIsNoFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutputPath::file('/build/');
    }

    public function testKeysCompareAddressesAsAStaticHostDoes(): void
    {
        self::assertSame(OutputPath::key('/caf%C3%A9/'), OutputPath::key('/café'));
        self::assertSame('/', OutputPath::key('/'));
    }

    /** @return iterable<array{string, string, string}> */
    public static function references(): iterable
    {
        yield 'absolute' => ['/blog/hello', '/about', '/about'];
        yield 'relative to the folder' => ['/blog/hello', 'world', '/blog/world'];
        yield 'parent' => ['/blog/2026/hello', '../index', '/blog/index'];
        yield 'dot' => ['/blog/hello', './', '/blog/'];
        yield 'above the root' => ['/a', '../../b', '/b'];
        yield 'a query alone' => ['/search', '?q=x', '/search?q=x'];
        yield 'the query kept' => ['/a/b', 'c?d=1', '/a/c?d=1'];
        yield 'from a style sheet' => ['/build/app.css', 'fonts/a.woff2', '/build/fonts/a.woff2'];
    }

    #[DataProvider('references')]
    public function testAReferenceIsResolvedAgainstItsDocument(string $base, string $reference, string $resolved): void
    {
        self::assertSame($resolved, OutputPath::resolve($base, $reference));
    }
}
