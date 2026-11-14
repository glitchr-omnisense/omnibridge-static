<?php

namespace Omnibridge\Static\Tests;

use Omnibridge\Static\Build\Record;
use Omnibridge\Static\Build\Summary;
use Omnibridge\Static\Exporter;
use Omnibridge\Static\Report;
use Omnibridge\Static\Tests\Fixtures\ContentSite;
use Omnibridge\Static\Tests\Fixtures\Folder;
use Omnibridge\Static\StaticRouteProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTwoCleanBuildsAreTheSameByteForByte(): void
    {
        $second = sys_get_temp_dir().'/omnibridge-static-second-'.getmypid();
        try {
            (new Exporter())->export(ContentSite::site($this->root, cached: false), $this->out);
            // The second with a cache of its own: what an export keeps aside changes nothing in its folder.
            (new Exporter())->export(ContentSite::site($this->root), $second);

            self::assertSame(Folder::tree($this->out), Folder::tree($second));
            self::assertFileEquals($this->out.'/'.Exporter::REPORT, $second.'/'.Exporter::REPORT);
            self::assertFileDoesNotExist($second.'/'.Record::FILE, 'the record stays in the cache');
        } finally {
            Folder::remove($second);
        }
    }

    public function testADeclaredConfigurationChangeRendersEveryPageAgainAndSaysWhichInput(): void
    {
        $this->incremental();
        ContentSite::put($this->root.'/config.json', '{"title": "Atelier Exemple, Strasbourg"}');

        $lines = [];
        $report = $this->incremental($lines);

        self::assertSame(Summary::INCREMENTAL, $report->summary?->mode);
        self::assertSame([7, 0, 5, 7, 0], self::counts($report), 'the four pages and 404.html carry the title; the redirection and the picture do not');
        self::assertContains('changed since the last export: 1 declared input ('.$this->root.'/config.json)', $lines);
        $this->assertSameAsAFullExport();
    }

    public function testAnUndeclaredPhpFileTheExportLoadedMakesTheNextExportAFullOne(): void
    {
        $this->incremental();
        self::assertArrayHasKey($this->root.'/footer.php', self::record($this->root)['loaded'], 'the record lists the PHP files the export loaded');
        ContentSite::put($this->root.'/footer.php', "<?php return '<footer>Atelier Exemple, 2026</footer>';\n");

        $report = $this->incremental();

        self::assertSame(Summary::FULL, $report->summary?->mode);
        self::assertSame('a PHP file the earlier export loaded changed: '.$this->root.'/footer.php', $report->summary->why);
        self::assertSame([7, 0, 12, 0, 0], self::counts($report), 'nothing taken from the cache, every file rewritten');
        self::assertStringContainsString('Atelier Exemple, 2026', (string) file_get_contents($this->out.'/a/index.html'));
        $this->assertSameAsAFullExport();

        // The new record holds the new file: the export after it is incremental again.
        $report = $this->incremental();
        self::assertSame(Summary::INCREMENTAL, $report->summary?->mode);
        self::assertSame([0, 7, 0, 12, 0], self::counts($report));
    }

    public function testAPhpFileTheExportLoadedThatIsGoneMakesTheNextExportAFullOne(): void
    {
        $this->incremental();
        $record = self::record($this->root);
        $record['loaded'][$this->root.'/gone.php'] = str_repeat('0', 64);
        file_put_contents($this->root.'/cache/'.Record::FILE, json_encode($record));

        $report = $this->incremental();

        self::assertSame('a PHP file the earlier export loaded is gone: '.$this->root.'/gone.php', $report->summary?->why);
        $this->assertSameAsAFullExport();
    }

    public function testALoadedFileTheSiteIgnoresIsNotCompared(): void
    {
        $export = fn (): Report => (new Exporter())->export(ContentSite::site($this->root)->ignoreLoaded($this->root.'/foot*'), $this->out);
        $export();
        self::assertArrayNotHasKey($this->root.'/footer.php', self::record($this->root)['loaded']);
        // A file the application writes afresh at every boot with the same meaning (a compiled container
        // and its build time): rewritten here with the same output.
        ContentSite::put($this->root.'/footer.php', "<?php\n\n// Built at 12:00.\nreturn '<footer>Atelier Exemple</footer>';\n");
        ContentSite::$renders = [];

        $report = $export();

        self::assertSame(Summary::INCREMENTAL, $report->summary?->mode);
        self::assertSame([0, 7, 0, 12, 0], self::counts($report));
    }

    /** @return iterable<string, array{list<string>, mixed, string}> the record's entry changed, its new value, the reason */
    public static function untrustedRecords(): iterable
    {
        yield 'another PHP version' => [['environment', 'php'], '8.1.0', 'PHP '.\PHP_VERSION.', the earlier export ran on 8.1.0'];
        yield 'another extension' => [['environment', 'extensions', 'imaginary'], '1.0.0', 'the PHP extensions changed: imaginary'];
        yield 'another package version' => [['environment', 'packages', 'vendor/package'], '1.0.0@abc', 'the installed packages changed: vendor/package'];
        yield 'another version of omnibridge/static' => [['code'], 'another', 'the cache was filled by another version of omnibridge/static'];
        yield 'another format' => [['format'], 0, 'no record of an earlier export in the cache'];
        yield 'no record' => [[], null, 'no record of an earlier export in the cache'];
    }

    /** @param list<string> $entry */
    #[DataProvider('untrustedRecords')]
    public function testACacheItsRecordDoesNotVouchForGivesAFullExport(array $entry, mixed $value, string $why): void
    {
        $this->incremental();
        file_put_contents($this->root.'/cache/'.Record::FILE, [] === $entry ? 'not a record' : json_encode(self::changed(self::record($this->root), $entry, $value)));
        ContentSite::put($this->root.'/content/b.txt', 'Beta, again');

        $report = $this->incremental();

        self::assertSame(Summary::FULL, $report->summary?->mode);
        self::assertSame($why, $report->summary->why);
        self::assertSame([7, 0, 12, 0, 0], self::counts($report));
        $this->assertSameAsAFullExport();
        self::assertSame(Summary::INCREMENTAL, $this->incremental()->summary?->mode, 'the record written again');
    }

    public function testAnExportCutShortLeavesACacheTheNextExportDoesNotTrust(): void
    {
        $this->incremental();
        $site = ContentSite::site($this->root)->provide(new class implements StaticRouteProviderInterface {
            public function pages(): iterable
            {
                throw new \RuntimeException('The content cannot be read.');
            }
        });
        try {
            (new Exporter())->export($site, $this->out);
            self::fail('the export went on');
        } catch (\RuntimeException $e) {
            self::assertSame('The content cannot be read.', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->root.'/cache/'.Record::FILE);

        $report = $this->incremental();

        self::assertSame('no record of an earlier export in the cache', $report->summary?->why);
        $this->assertSameAsAFullExport();
    }

    public function testADeletedPageIsRemovedFromTheFolderTheReportAndTheRecord(): void
    {
        $this->incremental();
        self::assertContains('/c', array_column(self::record($this->root)['addresses'], 'path'));
        unlink($this->root.'/content/c.txt');

        $report = $this->incremental();

        self::assertSame(Summary::INCREMENTAL, $report->summary?->mode);
        self::assertSame(['/'], array_keys(ContentSite::$renders), 'the list alone: it showed the page');
        self::assertSame([1, 5, 2, 9, 1], self::counts($report), 'index.html and sitemap.xml written, c/index.html removed');
        self::assertDirectoryDoesNotExist($this->out.'/c');
        self::assertArrayNotHasKey('/c', $report->pages);
        self::assertNotContains('/c', array_column(self::record($this->root)['addresses'], 'path'));
        $this->assertSameAsAFullExport();
    }

    public function testTheRecordSaysWhatEachAddressDependedOn(): void
    {
        $this->incremental();
        $record = self::record($this->root);
        $addresses = [];
        foreach ($record['addresses'] as $address) {
            $addresses[$address['role'].' '.$address['path']] = $address['inputs'];
        }
        ksort($addresses);

        self::assertSame([
            'not-found /404' => [],
            'page /' => [$this->root.'/content'],
            'page /a' => [$this->root.'/content/a.txt'],
            'page /b' => [$this->root.'/content/b.txt'],
            'page /c' => [$this->root.'/content/c.txt'],
            'page /old' => [],
            'resource /logo.png' => [],
        ], $addresses);
        self::assertSame(1, $record['format']);
        self::assertSame(\PHP_VERSION, $record['environment']['php']);
        self::assertArrayNotHasKey('omnibridge/static', $record['environment']['packages'], 'the root package is seen through the files it loads');
        self::assertArrayHasKey('phpunit/phpunit', $record['environment']['packages']);
        self::assertArrayHasKey($this->root.'/layout.html', $record['inputs']);
        self::assertArrayHasKey($this->root.'/config.json', $record['inputs']);
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

    /** @param list<string> $lines the lines the export logs */
    private function incremental(array &$lines = []): Report
    {
        ContentSite::$renders = [];
        $report = (new Exporter(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }))->export(ContentSite::site($this->root), $this->out);
        self::assertSame([], $report->errors());

        return $report;
    }

    /**
     * The cache's record, as JSON gives it.
     *
     * @return array{format: int, code: string, environment: array{php: string, extensions: array<string, string>, packages: array<string, string>}, loaded: array<string, string|null>, inputs: array<string, string>, addresses: list<array{path: string, role: string, fingerprint: string|null, inputs: list<string>|null}>}
     */
    private static function record(string $root): array
    {
        $record = json_decode((string) file_get_contents($root.'/cache/'.Record::FILE), true);
        self::assertIsArray($record);

        /** @var array{format: int, code: string, environment: array{php: string, extensions: array<string, string>, packages: array<string, string>}, loaded: array<string, string|null>, inputs: array<string, string>, addresses: list<array{path: string, role: string, fingerprint: string|null, inputs: list<string>|null}>} $record */
        return $record;
    }

    /**
     * An array with one entry, at the end of a path of keys, set.
     *
     * @param array<mixed> $array
     * @param list<string> $keys
     *
     * @return array<mixed>
     */
    private static function changed(array $array, array $keys, mixed $value): array
    {
        $key = array_shift($keys);
        if (null === $key) {
            return $array;
        }
        $array[$key] = [] === $keys ? $value : self::changed(\is_array($array[$key] ?? null) ? $array[$key] : [], $keys, $value);

        return $array;
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
