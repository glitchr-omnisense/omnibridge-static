<?php

namespace Omnibridge\Static\Tests\Build;

use Omnibridge\Static\Build\Fingerprint;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\Tests\Fixtures\Folder;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/omnibridge-static-fingerprint-'.getmypid();
        mkdir($this->dir.'/one/news', 0o777, true);
        mkdir($this->dir.'/two/news', 0o777, true);
        foreach (['one', 'two'] as $copy) {
            file_put_contents($this->dir.'/'.$copy.'/news/a.md', 'A');
            file_put_contents($this->dir.'/'.$copy.'/news/b.md', 'B');
        }
    }

    protected function tearDown(): void
    {
        Folder::remove($this->dir);
    }

    public function testInputsAreTheirContentWhereverTheCheckoutIs(): void
    {
        foreach (['news/a.md', 'news', 'news/*.md'] as $input) {
            self::assertSame(Fingerprint::inputs([$this->dir.'/one/'.$input]), Fingerprint::inputs([$this->dir.'/two/'.$input]), $input);
        }
        self::assertSame(Fingerprint::inputs(['x', 'y']), Fingerprint::inputs(['y', 'x', 'x']), 'whatever the order');
    }

    public function testAChangeAnAdditionOrARemovalChangesThem(): void
    {
        $file = Fingerprint::inputs([$this->dir.'/one/news/a.md']);
        $folder = Fingerprint::inputs([$this->dir.'/one/news']);
        $glob = Fingerprint::inputs([$this->dir.'/one/news/*.md']);

        file_put_contents($this->dir.'/one/news/c.md', 'C');
        self::assertSame($file, Fingerprint::inputs([$this->dir.'/one/news/a.md']));
        self::assertNotSame($folder, $folder = Fingerprint::inputs([$this->dir.'/one/news']), 'a file added');
        self::assertNotSame($glob, $glob = Fingerprint::inputs([$this->dir.'/one/news/*.md']));

        unlink($this->dir.'/one/news/b.md');
        self::assertNotSame($folder, Fingerprint::inputs([$this->dir.'/one/news']), 'a file removed');
        self::assertNotSame($glob, Fingerprint::inputs([$this->dir.'/one/news/*.md']));

        file_put_contents($this->dir.'/one/news/a.md', 'A, again');
        self::assertNotSame($file, Fingerprint::inputs([$this->dir.'/one/news/a.md']), 'a file changed');
        self::assertNotSame(Fingerprint::inputs(['commit abc']), Fingerprint::inputs(['commit abd']), 'a value is taken as it is');
    }

    public function testAPageWithAnObjectAmongItsParametersHasNoFingerprint(): void
    {
        self::assertNotNull(Fingerprint::page(new StaticPage('/a', 'post', ['slug' => 'a', 'tags' => ['x', 1, null]])));
        self::assertNull(Fingerprint::page(new StaticPage('/a', 'post', ['post' => new \stdClass()])));
        self::assertNotSame(Fingerprint::page(new StaticPage('/a', locale: 'fr')), Fingerprint::page(new StaticPage('/a', locale: 'en')));
        self::assertMatchesRegularExpression('~^[0-9a-f]{64}$~', Fingerprint::code());
    }
}
