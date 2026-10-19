<?php

declare(strict_types=1);

namespace App\Community;

/**
 * @module Community/BrandingStore
 * @description Stores an organizer-supplied logo (SVG/PNG/JPEG/WebP, ≤ 512 KB) under var/branding
 *     and serves it back with a safe content type. SVGs are served with a sandboxing CSP.
 *
 * @input file path or uploaded bytes
 * @output stored file name ("logo.svg") or null
 */
final class BrandingStore
{
    public const MAX_BYTES = 524_288;
    private const TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __construct(private readonly string $directory)
    {
    }

    public function storeLogo(string $sourcePath, ?string $originalName = null): string
    {
        $ext = strtolower(pathinfo($originalName ?? $sourcePath, \PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new ConfigException(['Logo must be an SVG, PNG, JPEG or WebP file.']);
        }
        $size = filesize($sourcePath);
        if (false === $size || $size > self::MAX_BYTES || 0 === $size) {
            throw new ConfigException(['Logo must be between 1 byte and 512 KB.']);
        }
        $bytes = (string) file_get_contents($sourcePath);
        if ('svg' === $ext && (!str_contains($bytes, '<svg') || preg_match('~<script|on[a-z]+\s*=|javascript:|<foreignObject|<iframe|<embed|<object~i', $bytes))) {
            throw new ConfigException(['SVG logo must not contain scripts, event handlers or embedded documents.']);
        }
        if ('svg' !== $ext) {
            $info = @getimagesizefromstring($bytes);
            if (false === $info) {
                throw new ConfigException(['Logo is not a readable image.']);
            }
            // The decoded type must match the extension, and no markup may ride along (polyglot files).
            if ($info['mime'] !== self::TYPES[$ext] || preg_match('~<(script|html|body|iframe|svg)\b|<\?php~i', $bytes)) {
                throw new ConfigException(['Logo content does not match its file type.']);
            }
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create branding directory.');
        }
        foreach (glob($this->directory.'/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        $name = 'logo.'.('jpeg' === $ext ? 'jpg' : $ext);
        file_put_contents($this->directory.'/'.$name, $bytes);

        return $name;
    }

    /** Public URL of a stored logo with a content version, so a replaced logo is never served from a cache. */
    public function url(?string $name): ?string
    {
        $file = null !== $name ? $this->find($name) : null;

        return null === $file ? null : '/branding/'.$name.'?v='.substr((string) hash_file('sha256', $file['path']), 0, 10);
    }

    /** @return array{path:string, type:string}|null */
    public function find(string $name): ?array
    {
        if (!preg_match('~^logo\.(svg|png|jpg|webp)$~', $name)) {
            return null;
        }
        $path = $this->directory.'/'.$name;

        return is_file($path) ? ['path' => $path, 'type' => self::TYPES[pathinfo($name, \PATHINFO_EXTENSION)]] : null;
    }
}
