<?php

declare(strict_types=1);

namespace App\Security\Session;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * @module Security/Session/HostCookieStorageFactory
 * @description Over HTTPS the session cookie is named "__Host-" + SESSION_COOKIE_NAME. Browsers accept such a
 *     cookie only with Secure, Path=/ and no Domain, so neither a sibling subdomain nor a plain-http page can set
 *     or overwrite it. Plain-http installs (local tests) keep the name as configured; a name that already has a
 *     __Host- or __Secure- prefix is left alone. Decorates the framework's native session storage factory.
 */
final class HostCookieStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(private readonly SessionStorageFactoryInterface $inner)
    {
    }

    public static function cookieName(string $configured, bool $secure): string
    {
        return $secure && !str_starts_with($configured, '__Host-') && !str_starts_with($configured, '__Secure-') ? '__Host-'.$configured : $configured;
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = $this->inner->createStorage($request);
        $name = self::cookieName($storage->getName(), (bool) $request?->isSecure());
        if ($name !== $storage->getName()) {
            $storage->setName($name);
        }

        return $storage;
    }
}
