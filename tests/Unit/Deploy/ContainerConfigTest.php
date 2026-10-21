<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Traceability: security audit run-1 leads 4, 9 and 15 (container configuration). Pins what the live
 * checks showed: PHP-FPM has no TCP listener (FastCGI can set PHP settings and the workers hold the app's
 * secrets), the email-sending endpoints run in their own pool, and nginx refuses percent-encoded paths and
 * keys its limits on the normalized $uri.
 */
#[CoversNothing]
final class ContainerConfigTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$path);
    }

    public function testPhpFpmListensOnlyOnUnixSocketsReachableByNginx(): void
    {
        $pools = self::read('docker/php/fpm-pool.conf');
        preg_match_all('~^\s*listen\s*=\s*(\S+)~m', $pools, $m);
        self::assertSame(['/run/php/app.sock', '/run/php/mail.sock'], $m[1], 'no host:port listener');
        self::assertSame(2, preg_match_all('~^listen\.mode = 0660$~m', $pools));
        self::assertSame(2, preg_match_all('~^listen\.group = fcgi-nginx$~m', $pools));
        self::assertMatchesRegularExpression('~\[mail\][^\[]*pm\.max_children = \$\{FPM_MAIL_MAX_CHILDREN\}~', $pools);
        self::assertStringContainsString('cgi-fcgi -bind -connect "$sock"', self::read('docker/php/healthcheck.sh'));

        $compose = Yaml::parse(self::read('compose.yaml'));
        self::assertContains('php-socket:/run/php', $compose['services']['app']['volumes']);
        self::assertContains('php-socket:/run/php:ro', $compose['services']['nginx']['volumes']);
        foreach ($compose['services'] as $name => $service) {
            $mounts = implode(' ', $service['volumes'] ?? []);
            self::assertTrue(!str_contains($mounts, 'php-socket') || \in_array($name, ['app', 'nginx'], true), "$name must not reach PHP-FPM");
        }
    }

    public function testNginxRoutesMailEndpointsToTheirPoolAndNeverToATcpUpstream(): void
    {
        $conf = (string) preg_replace('~#[^\n]*~', '', self::read('docker/nginx/default.conf').self::read('docker/nginx/app-fastcgi.conf'));
        preg_match_all('~fastcgi_pass\s+([^;]+);~', $conf, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $upstream) {
            self::assertStringStartsWith('unix:/run/php/', $upstream);
        }
        foreach (['/api/auth/request-link', '/api/disputes'] as $path) {
            self::assertMatchesRegularExpression('~location = '.preg_quote($path, '~').' \{\s*fastcgi_pass unix:/run/php/mail\.sock;~', $conf);
        }
    }

    public function testNginxRefusesEncodedPathsAndKeysLimitsOnTheNormalizedUri(): void
    {
        $conf = self::read('docker/nginx/default.conf');
        self::assertStringContainsString('if ($request_uri ~ "^[^?]*%") {', $conf);
        self::assertMatchesRegularExpression('~map \$uri \$api_client~', $conf);
        self::assertMatchesRegularExpression('~map \$uri \$auth_client~', $conf);
        self::assertDoesNotMatchRegularExpression('~map \$request_uri \$(api|auth)_client~', $conf);
        self::assertStringContainsString('limit_req_log_level info;', $conf, 'rejected /auth/confirm?t=… lines stay out of the error log');
    }
}
