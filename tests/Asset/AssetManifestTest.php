<?php

namespace Omnibridge\Static\Tests\Asset;

use Omnibridge\Static\Asset\AssetManifest;
use PHPUnit\Framework\TestCase;

final class AssetManifestTest extends TestCase
{
    public function testAViteManifestsFilesAreRelativeToItsFolder(): void
    {
        $manifest = json_encode([
            'src/main.ts' => ['file' => 'assets/main-4f3a.js', 'src' => 'src/main.ts', 'isEntry' => true, 'css' => ['assets/main-1a2b.css'], 'assets' => ['assets/logo-9f.svg'], 'imports' => ['_shared-3c.js']],
            '_shared-3c.js' => ['file' => 'assets/shared-3c.js'],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame(['/build/assets/logo-9f.svg', '/build/assets/main-1a2b.css', '/build/assets/main-4f3a.js', '/build/assets/shared-3c.js'], AssetManifest::parse($manifest, '/build'));
    }

    public function testAWebpackManifestNamesPublicPaths(): void
    {
        $manifest = '{"build/app.js": "/build/app.3f2a.js", "build/app.css": "/build/app.9b1c.css", "build/cdn.js": "https://cdn.example.org/cdn.js"}';

        self::assertSame(['/build/app.3f2a.js', '/build/app.9b1c.css'], AssetManifest::parse($manifest));
    }

    public function testEncoresEntrypoints(): void
    {
        $entrypoints = '{"entrypoints": {"app": {"js": ["/build/runtime.js", "/build/app.js"], "css": ["/build/app.css"]}, "admin": {"js": ["/build/runtime.js"]}}}';

        self::assertSame(['/build/app.css', '/build/app.js', '/build/runtime.js'], AssetManifest::parse($entrypoints));
    }

    public function testAMissingManifestSaysSo(): void
    {
        $this->expectException(\RuntimeException::class);
        AssetManifest::read('/nowhere/manifest.json');
    }
}
