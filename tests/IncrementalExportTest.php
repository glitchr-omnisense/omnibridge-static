<?php

namespace Omnibridge\Static\Tests;

use Omnibridge\Static\Build\Summary;
use Omnibridge\Static\Exporter;
use Omnibridge\Static\Report;
use Omnibridge\Static\Tests\Fixtures\ContentSite;
use Omnibridge\Static\Tests\Fixtures\Folder;
use PHPUnit\Framework\TestCase;

/**
 * An incremental export gives the files a full export gives, from whatever
 * the folder held: the same tree, byte for byte, the report included.
 */
final class IncrementalExportTest extends TestCase
{
    private string $root;
    private string $out;
    private string $full;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/omnibridge-static-content-'.getmypid();
        $this->out = sys_get_temp_dir().'/omnibridge-static-incremental-'.getmypid();
        $this->full = sys_get_temp_dir().'/omnibridge-static-full-'.getmypid();
        ContentSite::make($this->root);
        ContentSite::$renders = [];
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->out, $this->full] as $dir) {
            Folder::remove($dir);
        }
    }

    public function testEachChangeRendersAndWritesWhatItTouchesAndGivesTheFullExport(): void
    {
        $first = $this->incremental();
        self::assertSame(['/', '/a', '/b', '/c', '/old', '/404', '/logo.png'], array_keys(ContentSite::$renders));
        self::assertSame([7, 0, 12, 0, 0], self::counts($first), 'rendered, reused, written, unchanged, removed: all new');
        $this->assertSameAsAFullExport();
        $alpha = (int) fileinode($this->out.'/a/index.html');

        // A page changed: it and the list that shows its title.
        ContentSite::put($this->root.'/content/b.txt', 'Beta, again');
        $report = $this->incremental();
        self::assertSame(['/', '/b'], array_keys(ContentSite::$renders));
        self::assertSame([2, 5, 2, 10, 0], self::counts($report));
        self::assertStringContainsString('Beta, again', (string) file_get_contents($this->out.'/index.html'));
        self::assertSame($alpha, fileinode($this->out.'/a/index.html'), 'a file whose bytes are the same is left as it is');
        $this->assertSameAsAFullExport();

        // The route list changed: a page removed, another added.
        unlink($this->root.'/content/c.txt');
        ContentSite::put($this->root.'/content/d.txt', 'Delta');
        $report = $this->incremental();
        self::assertSame(['/', '/d'], array_keys(ContentSite::$renders));
        self::assertSame([2, 5, 3, 9, 1], self::counts($report), 'the list, the new page and sitemap.xml written; c/index.html removed');
        self::assertFileDoesNotExist($this->out.'/c/index.html');
        self::assertDirectoryDoesNotExist($this->out.'/c');
        $this->assertSameAsAFullExport();

        // A shared asset changed: copied again, nothing rendered.
        ContentSite::put($this->root.'/public/style.css', 'body { color: navy } @font-face { font-family: F; src: url(font.woff2) }');
        $report = $this->incremental();
        self::assertSame([], ContentSite::$renders);
        self::assertSame([0, 7, 1, 11, 0], self::counts($report));
        $this->assertSameAsAFullExport();

        // The shared layout changed: every page rendered again; the files whose bytes did not change kept.
        ContentSite::put($this->root.'/layout.html', '<!DOCTYPE html><html lang="en"><head><link rel="stylesheet" href="/style.css"></head><body>{body}</body></html>');
        $report = $this->incremental();
        self::assertSame(['/', '/a', '/b', '/d', '/old', '/404', '/logo.png'], array_keys(ContentSite::$renders));
        self::assertSame([7, 0, 5, 7, 0], self::counts($report), 'the four pages and 404.html written; the redirection, the picture and the rest unchanged');
        $this->assertSameAsAFullExport();

        // Nothing changed: nothing rendered, nothing written.
        $report = $this->incremental();
        self::assertSame([0, 7, 0, 12, 0], self::counts($report));
        self::assertCount(7, glob($this->root.'/cache/*.response') ?: [], 'the cache holds the answers of the last export, no more');
    }

    public function testAnIncrementalExportRepairsWhateverTheFolderHolds(): void
    {
        $this->incremental();
        file_put_contents($this->out.'/a/index.html', 'edited by hand');
        file_put_contents($this->out.'/extra.html', 'left by someone');
        unlink($this->out.'/sitemap.xml');
        mkdir($this->out.'/sitemap.xml');
        file_put_contents($this->out.'/sitemap.xml/inside', 'a folder where a file goes');
        mkdir($this->out.'/empty/deeper', 0o777, true);

        $report = $this->incremental();

        self::assertSame([0, 7, 2, 10, 2], self::counts($report), 'a/index.html and sitemap.xml written; extra.html and the file in the way removed');
        $this->assertSameAsAFullExport();
    }

    public function testAnEmptyFolderAndAWarmCacheRenderNothing(): void
    {
        $this->incremental();
        Folder::remove($this->out);

        $report = $this->incremental();

        self::assertSame([0, 7, 12, 0, 0], self::counts($report), 'the CI case: a fresh checkout, the cache restored');
        $this->assertSameAsAFullExport();
    }

    public function testAnExportByAnotherVersionForcesAFullExport(): void
    {
        $this->incremental();
        $report = (string) file_get_contents($this->out.'/'.Exporter::REPORT);
        self::assertMatchesRegularExpression('~"build": \{\s+"code": "[0-9a-f]{64}"\s+\}~', $report);
        file_put_contents($this->out.'/'.Exporter::REPORT, (string) preg_replace('~"code": "[0-9a-f]{64}"~', '"code": "another"', $report));

        $report = $this->incremental();

        self::assertSame(Summary::FULL, $report->summary?->mode);
        self::assertSame('the earlier export was made by another version of omnibridge/static', $report->summary->why);
        self::assertSame([7, 0, 12, 0, 0], self::counts($report), 'nothing taken from the cache, every file rewritten');
        $this->assertSameAsAFullExport();
    }

    public function testFullRendersAndRewritesEverything(): void
    {
        $this->incremental();
        $report = (new Exporter())->export(ContentSite::site($this->root), $this->out, full: true);

        self::assertSame(Summary::FULL, $report->summary?->mode);
        self::assertSame('asked', $report->summary->why);
        self::assertSame([7, 0, 12, 0, 0], self::counts($report));
    }

    public function testOnlyRendersItsAddressesAndReusesWhatTheInputsProveUnaffected(): void
    {
        $this->incremental();
        ContentSite::$renders = [];
        $lines = [];
        $report = (new Exporter(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }))->export(ContentSite::site($this->root), $this->out, only: ['/a']);

        self::assertSame(['/a'], array_keys(ContentSite::$renders));
        self::assertSame([1, 6, 0, 12, 0], self::counts($report));
        self::assertSame(1, $report->summary?->targeted);
        self::assertSame(1, $report->summary->matched);
        self::assertSame('incremental: 1 rendered, 6 reused; 0 written, 12 unchanged, 0 removed', end($lines));
        $this->assertSameAsAFullExport();
    }

    public function testWithoutDeclaredInputsOnlyRendersEverythingAndSaysWhy(): void
    {
        $lines = [];
        $export = function () use (&$lines): Report {
            return (new Exporter(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }))->export(ContentSite::site($this->root, declared: false), $this->out, only: ['/a']);
        };
        $export();
        ContentSite::$renders = [];
        $lines = [];

        $report = $export();

        self::assertCount(7, ContentSite::$renders, 'nothing proves the other pages unaffected');
        self::assertSame([7, 0, 0, 12, 0], self::counts($report), 'every page rendered, no file written: the writing is incremental still');
        self::assertSame(6, $report->summary?->undeclared);
        self::assertContains('--only: 6 addresses outside the patterns rendered too (6 whose inputs are not declared: nothing proves them unaffected; 0 whose inputs changed or are not in the cache)', $lines);
        self::assertArrayNotHasKey('inputs', $report->files['index.html'], 'no fingerprint where no input is declared');
        $this->assertSameAsAFullExport(declared: false);
    }

    public function testTheReportHoldsEachRenderedFilesFingerprintAndNoCounts(): void
    {
        $report = $this->incremental();

        self::assertMatchesRegularExpression('~^[0-9a-f]{64}$~', $report->files['index.html']['inputs'] ?? '');
        self::assertArrayNotHasKey('inputs', $report->files['style.css'], 'a copied file: its checksum says it all');
        self::assertNotSame($report->files['a/index.html']['inputs'] ?? null, $report->files['b/index.html']['inputs'] ?? null);
        self::assertStringNotContainsString('unchanged', $report->toJson());
        self::assertFileDoesNotExist(\dirname($this->out).'/.'.basename($this->out).'.staging', 'the staging folder is gone');
    }

    private function incremental(): Report
    {
        ContentSite::$renders = [];
        $report = (new Exporter())->export(ContentSite::site($this->root), $this->out);
        self::assertSame([], $report->errors());

        return $report;
    }

    /** The folder as a full export of the same files gives it, into an empty folder, without a cache. */
    private function assertSameAsAFullExport(bool $declared = true): void
    {
        $renders = ContentSite::$renders;
        Folder::remove($this->full);
        (new Exporter())->export(ContentSite::site($this->root, $declared, cached: false), $this->full, full: true);
        ContentSite::$renders = $renders;

        self::assertSame(Folder::tree($this->full), Folder::tree($this->out));
    }

    /** @return array{int, int, int, int, int} rendered, reused, written, unchanged, removed */
    private static function counts(Report $report): array
    {
        $summary = $report->summary ?? self::fail('no summary');

        return [$summary->rendered, $summary->reused, $summary->written, $summary->unchanged, $summary->removed];
    }
}
