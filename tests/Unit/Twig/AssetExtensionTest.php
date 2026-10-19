<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\AssetExtension;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: stale-cache fix — asset URLs carry the version and a fingerprint of the asset files, so
 * any change to any asset gives every asset a new URL (no cache can mix old and new ES modules).
 */
final class AssetExtensionTest extends TestCase
{
    public function testUrlChangesWhenAnyAssetChanges(): void
    {
        $dir = sys_get_temp_dir().'/assets-'.bin2hex(random_bytes(4));
        mkdir($dir.'/js/lib', 0o777, true);
        file_put_contents($dir.'/js/home.js', 'import "./lib/a.js";');
        file_put_contents($dir.'/js/lib/a.js', 'export const a = 1;');

        $first = (new AssetExtension('1.2.0-beta.1', $dir))->assetPath('js/home.js');
        self::assertMatchesRegularExpression('~^/assets/v1\.2\.0-beta\.1\.[0-9a-f]{10}/js/home\.js$~', $first);
        self::assertSame($first, (new AssetExtension('1.2.0-beta.1', $dir))->assetPath('/js/home.js'), 'stable while nothing changes');

        file_put_contents($dir.'/js/lib/a.js', 'export const a = 22;'); // only an imported module changed
        self::assertNotSame($first, (new AssetExtension('1.2.0-beta.1', $dir))->assetPath('js/home.js'));
        self::assertStringStartsWith('/assets/v1.2.1.', (new AssetExtension('1.2.1', $dir))->assetPath('css/app.css'));
        self::assertStringStartsWith('/assets/vweird-v-.', (new AssetExtension('weird v/', $dir))->assetPath('x'), 'unsafe characters never reach the URL');
    }
}
