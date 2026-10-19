<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @module Twig/AssetExtension
 * @description asset_path('js/home.js') → /assets/v<version>.<fingerprint>/js/home.js. The fingerprint
 *     changes whenever any file under public/assets changes, so browsers and any cache in front of the
 *     app (e.g. a CDN or tunnel edge) can never mix an old module with new ones, and versioned files can
 *     be cached for a long time. nginx maps the versioned prefix back to public/assets.
 *
 * @input relative asset path; the app version; the public/assets directory
 * @output root-relative URL
 * @dependencies none
 */
final class AssetExtension extends AbstractExtension
{
    private ?string $tag = null;

    public function __construct(
        private readonly string $appVersion,
        #[Autowire('%kernel.project_dir%/public/assets')]
        private readonly string $assetDirectory,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('asset_path', $this->assetPath(...))];
    }

    public function assetPath(string $path): string
    {
        return '/assets/v'.$this->tag().'/'.ltrim($path, '/');
    }

    /** Version plus a short hash of every asset's path, size and modification time. */
    public function tag(): string
    {
        if (null !== $this->tag) {
            return $this->tag;
        }
        $files = [];
        if (is_dir($this->assetDirectory)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->assetDirectory, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[substr($file->getPathname(), \strlen($this->assetDirectory))] = $file->getSize().':'.$file->getMTime();
                }
            }
        }
        ksort($files);
        $fingerprint = substr(hash('sha256', json_encode($files, \JSON_THROW_ON_ERROR)), 0, 10);

        return $this->tag = preg_replace('~[^A-Za-z0-9.-]~', '-', $this->appVersion).'.'.$fingerprint;
    }
}
