<?php

namespace Omnibridge\Static\Tests\Console;

use Omnibridge\Static\Console\Cli;
use Omnibridge\Static\Exporter;
use Omnibridge\Static\Tests\Fixtures\Folder;
use Omnibridge\Static\Tests\Fixtures\PlainApp;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $out;
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir().'/omnibridge-static-cli-'.getmypid();
    }

    protected function tearDown(): void
    {
        Folder::remove($this->out);
        Folder::remove(PlainApp::publicDir());
    }

    public function testBuildExportsTheSiteTheFileDeclaresAndFailsOnItsErrors(): void
    {
        $status = $this->cli()->run(['build', __DIR__.'/../Fixtures/site.php', '--out='.$this->out], [Cli::HOST_PUBLIC_DIR => PlainApp::publicDir()]);

        self::assertSame(1, $status, 'the fixture has one broken link');
        self::assertFileExists($this->out.'/index.html');
        self::assertContains('[error] broken-link /missing: linked from / (answered 404)', $this->lines);
        self::assertContains('    1 error broken-link', $this->lines);
    }

    public function testCheckComparesAnExportWithItsReport(): void
    {
        $this->cli()->run(['build', __DIR__.'/../Fixtures/site.php', '--out='.$this->out, '--quiet']);
        self::assertSame(0, $this->cli()->run(['check', $this->out]));

        file_put_contents($this->out.'/index.html', 'changed');
        file_put_contents($this->out.'/extra.html', 'extra');
        unlink($this->out.'/favicon.ico');
        $this->lines = [];
        self::assertSame(1, $this->cli()->run(['check', $this->out]));
        self::assertSame(['changed: index.html', 'missing: favicon.ico', 'not in the report: extra.html'], \array_slice($this->lines, 0, 3));
    }

    public function testAFileThatDeclaresNoSiteIsAUsageError(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'site');
        file_put_contents($file, '<?php return 42;');
        try {
            self::assertSame(2, $this->cli()->run(['build', $file, '--out='.$this->out]));
            self::assertStringContainsString('returns no Omnibridge\Static\Site', $this->lines[0]);
        } finally {
            unlink($file);
        }
    }

    public function testTheExampleSiteExportsWithoutAnError(): void
    {
        self::assertSame(0, $this->cli()->run(['build', __DIR__.'/../../examples/plain/site.php', '--out='.$this->out, '--strict', '--quiet']), implode("\n", $this->lines));
        self::assertSame(0, $this->cli()->run(['check', $this->out]));
        self::assertFileExists($this->out.'/posts/hello/index.html');
        self::assertFileExists($this->out.'/build/example.woff2');
    }

    public function testThePlainPhpScriptRunsWithoutComposer(): void
    {
        $process = proc_open([\PHP_BINARY, __DIR__.'/../../bin/omnibridge-static', 'build', __DIR__.'/../Fixtures/site.php', '--out='.$this->out, '--quiet'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        self::assertSame(1, proc_close($process), $err);
        self::assertStringContainsString('broken-link /missing', $err);
        self::assertFileExists($this->out.'/'.Exporter::REPORT);
    }

    private function cli(): Cli
    {
        return new Cli(function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
