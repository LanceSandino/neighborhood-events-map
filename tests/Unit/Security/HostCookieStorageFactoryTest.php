<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Session\HostCookieStorageFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Traceability: public-readiness hardening note "a __Host- session cookie is documented but not the default".
 * Over HTTPS the cookie is __Host-<name>: Secure, Path=/, no Domain, so no subdomain or plain-http page can set it.
 */
#[CoversClass(HostCookieStorageFactory::class)]
final class HostCookieStorageFactoryTest extends TestCase
{
    private static function storage(string $configured, ?Request $request): SessionStorageInterface
    {
        $inner = new class($configured) implements SessionStorageFactoryInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function createStorage(?Request $request): SessionStorageInterface
            {
                return new MockArraySessionStorage($this->name);
            }
        };

        return (new HostCookieStorageFactory($inner))->createStorage($request);
    }

    public function testHttpsSessionsUseAHostPrefixedCookie(): void
    {
        self::assertSame('__Host-nhm_session', self::storage('nhm_session', Request::create('https://map.example.org/me'))->getName());
        $proxied = Request::create('/me', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'map.example.org']); // as nginx passes it behind the tunnel
        self::assertSame('__Host-nhm_session', self::storage('nhm_session', $proxied)->getName());
    }

    public function testPlainHttpAndAlreadyPrefixedNamesAreLeftAlone(): void
    {
        self::assertSame('nhm_session', self::storage('nhm_session', Request::create('http://localhost:8087/me'))->getName());
        self::assertSame('nhm_session', self::storage('nhm_session', null)->getName());
        self::assertSame('__Host-nhm_session', self::storage('__Host-nhm_session', Request::create('https://map.example.org/'))->getName());
        self::assertSame('__Secure-sid', self::storage('__Secure-sid', Request::create('https://map.example.org/'))->getName());
    }
}
