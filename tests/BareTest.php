<?php

namespace Omnibridge\Static\Tests;

use PHPUnit\Framework\TestCase;

/**
 * omnibridge/static is framework-free: a PHP process of its own loads every
 * class and interface of src/ with the package's own classes alone - no
 * Composer, no vendor/ - and declares nothing of a framework on the way. The
 * adapters that speak to symfony/routing, symfony/http-foundation or a PSR-11
 * container name those types only in their signatures: they load without
 * them, and need them only when used.
 */
final class BareTest extends TestCase
{
    public function testEveryClassLoadsWithoutAnyOtherPackage(): void
    {
        $process = proc_open([\PHP_BINARY, __DIR__.'/Fixtures/bare.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $err.$out);
        $report = json_decode($out, true);
        self::assertIsArray($report, $err.$out);
        self::assertIsArray($report['loaded'] ?? null);
        self::assertIsArray($report['symbols'] ?? null);
        self::assertIsArray($report['files'] ?? null);

        $sources = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src', \FilesystemIterator::SKIP_DOTS)) as $_) {
            ++$sources;
        }
        self::assertCount($sources, $report['loaded'], 'every file of src/ declares its class or interface');
        self::assertContains('Omnibridge\Static\Exporter', $report['loaded']);
        self::assertSame([], array_values((array) preg_grep('~^(?:Symfony|Illuminate|Laravel|Doctrine|Twig|Psr|Base|App|Composer)\\\\~', $report['symbols'])), 'no class of a framework, a PSR package or Composer');
        self::assertSame([], array_values((array) preg_grep('~/vendor/~', $report['files'])), 'no file of vendor/');
    }
}
