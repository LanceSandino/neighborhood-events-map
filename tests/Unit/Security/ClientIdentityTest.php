<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ClientIdentity;
use App\Shared\RuntimeConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Traceability: hardening (per-IP limits) — IPv6 clients are keyed by their /64 network, so rotating
 * addresses inside one allocation does not multiply rate-limit and proof-of-work budgets.
 */
#[CoversClass(ClientIdentity::class)]
final class ClientIdentityTest extends TestCase
{
    private static function identity(): ClientIdentity
    {
        return new ClientIdentity(new RuntimeConfig('http://localhost', 'a@example.test', 'o@example.test', str_repeat('k', 40), '', '', 16, 120, [], 'none', '', '', false));
    }

    private static function ip(string $remote): string
    {
        return self::identity()->ip(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $remote]));
    }

    /** @return iterable<array{0:string,1:string}> */
    public static function addresses(): iterable
    {
        yield 'IPv4 unchanged' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv4-mapped IPv6 is IPv4' => ['::ffff:203.0.113.7', '203.0.113.7'];
        yield 'IPv6 keyed by /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'];
        yield 'compressed IPv6' => ['2001:db8::1', '2001:db8::/64'];
    }

    #[DataProvider('addresses')]
    public function testClientKey(string $remote, string $expected): void
    {
        self::assertSame($expected, self::ip($remote));
    }

    public function testAddressesInOneSlash64ShareABudgetButNeighboursDoNot(): void
    {
        self::assertSame(self::ip('2001:db8:1:2::1'), self::ip('2001:db8:1:2:ffff::9'));
        self::assertNotSame(self::ip('2001:db8:1:2::1'), self::ip('2001:db8:1:3::1'));
        $id = self::identity();
        $hash = static fn (string $r) => $id->ipHash(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $r]));
        self::assertSame($hash('2001:db8:1:2::1'), $hash('2001:db8:1:2::2'), 'proof-of-work binding uses the same key');
    }
}
