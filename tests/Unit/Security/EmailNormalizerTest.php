<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\EmailNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: hardening (email relay abuse) — addresses must name a real mail domain, and rate limits
 * count provider aliases (+tags, Gmail dots) of one mailbox together; audit run-1 lead 7 (plain local parts only).
 */
#[CoversClass(EmailNormalizer::class)]
final class EmailNormalizerTest extends TestCase
{
    /** @return iterable<array{0:string,1:?string}> */
    public static function inputs(): iterable
    {
        yield 'lower-cased and trimmed' => ['  Jane.Doe@Example.ORG ', 'jane.doe@example.org'];
        yield 'plus tags are kept in the address itself' => ['jane+halloween@example.org', 'jane+halloween@example.org'];
        yield 'IP-literal domain' => ['user@[127.0.0.1]', null];
        yield 'dot-less domain' => ['root@localhost', null];
        yield 'CRLF injection' => ["a@example.org\r\nBcc: x@example.org", null];
        yield 'empty' => ['', null];
        // Security audit run-1 lead 7: a quoted local part is valid RFC 5321, but Symfony Mime re-parses
        // `"x<victim@evil.test>"@attacker.test` as "name <addr>" and delivers to victim@evil.test, so the
        // account identity and the mailbox the link goes to would differ. Only plain (dot-atom) local parts.
        yield 'quoted local part with an angle address' => ['"a<victim@evil.test>"@attacker.test', null];
        yield 'quoted local part' => ['"jane doe"@example.org', null];
        yield 'percent hack' => ['jane%victim.test@example.org', null];
        yield 'query-like characters' => ['a?b=c&d@example.org', null];
        yield 'apostrophe is a real name' => ["O'Brien@Example.org", "o'brien@example.org"];
        yield 'dots, dashes, underscores and tags' => ['first.last-x_y+tag@example.org', 'first.last-x_y+tag@example.org'];
    }

    #[DataProvider('inputs')]
    public function testNormalize(string $in, ?string $expected): void
    {
        self::assertSame($expected, (new EmailNormalizer())->normalize($in));
    }

    public function testThrottleKeyFoldsProviderAliases(): void
    {
        $n = new EmailNormalizer();
        self::assertSame('nhmfixturealias@gmail.com', $n->throttleKey('nhm.fixture.alias+news@gmail.com'));
        self::assertSame('nhmfixturealias@gmail.com', $n->throttleKey('n.h.m.f.i.x.t.u.r.e.alias@googlemail.com'));
        self::assertSame('jane.doe@example.org', $n->throttleKey('jane.doe+x+y@example.org'), 'dots only fold for Gmail');
        self::assertSame('jane@example.org', $n->throttleKey('jane@example.org'));
    }

    public function testMask(): void
    {
        self::assertSame('j***@example.org', EmailNormalizer::mask('jane@example.org'));
    }
}
