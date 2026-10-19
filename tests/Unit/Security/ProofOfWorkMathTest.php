<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ProofOfWork;
use App\Shared\RuntimeConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


/**
 * Traceability: BLUEPRINT §5 (SHA-256 leading zero bits; strict encoding), SESSION_AND_AUTH_LIFECYCLE (precedence).
 */
#[CoversClass(ProofOfWork::class)]
#[CoversClass(RuntimeConfig::class)]
final class ProofOfWorkMathTest extends TestCase
{
    public function testLeadingZeroBits(): void
    {
        self::assertSame(0, ProofOfWork::leadingZeroBits("\x80"));
        self::assertSame(7, ProofOfWork::leadingZeroBits("\x01\xff"));
        self::assertSame(8, ProofOfWork::leadingZeroBits("\x00\x80"));
        self::assertSame(12, ProofOfWork::leadingZeroBits("\x00\x0f"));
        self::assertSame(256, ProofOfWork::leadingZeroBits(str_repeat("\x00", 32)));
        self::assertTrue(ProofOfWork::meetsDifficulty("\x00\x0f", 12));
        self::assertFalse(ProofOfWork::meetsDifficulty("\x00\x0f", 13));
    }

    public function testDigestUsesDocumentedMessage(): void
    {
        self::assertSame(hash('sha256', 'login:id-1:abcd:42', true), ProofOfWork::digest('login', 'id-1', 'abcd', '42'));
        // Known vector shared with the browser worker test (tests/js/sha256.test.mjs).
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', hash('sha256', 'abc'));
    }

    private static function config(string $idle = '', string $link = '', int $pow = 16): RuntimeConfig
    {
        return new RuntimeConfig('http://x', 'a@b.c', 'a@b.c', 'secret', $idle, $link, $pow, 120, ['b' => '3/900'], 'none', '', 'https://x', false);
    }

    public function testSessionAndLinkPrecedence(): void
    {
        self::assertSame(30, self::config()->sessionIdleMinutes(null));
        self::assertSame(20, self::config()->sessionIdleMinutes(20), 'YAML when env empty');
        self::assertSame(45, self::config('45')->sessionIdleMinutes(20), 'env wins');
        self::assertSame(5, self::config('1')->sessionIdleMinutes(null), 'clamped low');
        self::assertSame(240, self::config('999')->sessionIdleMinutes(null), 'clamped high');
        self::assertSame(15, self::config()->magicLinkMinutes(null));
        self::assertSame(10, self::config('', '10')->magicLinkMinutes(30));
        self::assertSame([3, 900], self::config()->limit('b'));
        self::assertSame(24, self::config(pow: 40)->powDifficultyClamped());
    }

    public function testRejectsBadLimitSpec(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuntimeConfig::parseLimit('three per hour');
    }
}
