<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\LogRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: BLUEPRINT §5 "Never put magic-link tokens in analytics/logs" (regression: Symfony's
 * request log included /auth/confirm?t=... URIs).
 */
#[CoversClass(LogRedactionProcessor::class)]
final class LogRedactionProcessorTest extends TestCase
{
    public function testTokensAreRedactedEverywhere(): void
    {
        $token = sprintf('%s%s', 'abcDEF123_-xyz', str_repeat('0', 28)); // token-shaped, assembled at run time
        $record = new LogRecord(new \DateTimeImmutable(), 'request', Level::Info, "Matched route for /auth/confirm?t=$token&x=1",
            ['request_uri' => "http://h/auth/confirm?t=$token", 'nested' => ['url' => "/auth/confirm?a=1&t=$token"], 'body' => "{\"t\":\"$token\"}", 'form' => "t=x&_csrf=SECRETVALUE"]);
        $out = (new LogRedactionProcessor())($record);
        $all = $out->message.json_encode($out->context);
        self::assertStringNotContainsString($token, $all);
        self::assertStringNotContainsString('SECRETVALUE', $all);
        self::assertStringContainsString('?t=[redacted]&x=1', $out->message);
        self::assertSame('/auth/confirm?a=1&t=[redacted]', $out->context['nested']['url']);
        self::assertSame('/plain/path?type=1', LogRedactionProcessor::scrub('/plain/path?type=1'), 'other parameters untouched');
    }

    public function testEmailAddressesAreMaskedIncludingInsideLoggedObjects(): void
    {
        $token = new class implements \Stringable {
            public function __toString(): string
            {
                return 'PostAuthenticationToken(user="jane.doe@example.org", roles="ROLE_USER")';
            }
        };
        $record = new LogRecord(new \DateTimeImmutable(), 'security', Level::Info, 'Authenticator successful for jane.doe@example.org', ['token' => $token, 'to' => 'j***@example.org']);
        $out = (new LogRedactionProcessor())($record);
        $all = $out->message.json_encode($out->context);
        self::assertStringNotContainsString('jane.doe@example.org', $all);
        self::assertStringContainsString('j***@example.org', $out->message);
        self::assertSame('PostAuthenticationToken(user="j***@example.org", roles="ROLE_USER")', $out->context['token']);
        self::assertSame('j***@example.org', $out->context['to'], 'already-masked addresses stay as they are');
    }
}
