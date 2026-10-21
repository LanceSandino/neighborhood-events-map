<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Security\ClientIdentity;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Traceability: security audit run-1 leads 4, 9 and 15 (container configuration) and the public-readiness
 * hardening notes. Pins what the live checks showed: PHP-FPM has no TCP listener (FastCGI can set PHP settings
 * and the workers hold the app's secrets), the email-sending endpoints run in their own pool, nginx refuses
 * percent-encoded paths, keys its limits on the normalized $uri and on the client's network (an IPv6 /64, as
 * the app does), and caps one client's requests in PHP below the pool sizes. Base images are pinned by
 * digest and the maintenance container gets no mail or routing credentials.
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
            self::assertMatchesRegularExpression('~location = '.preg_quote($path, '~').' \{\s*limit_conn perip \d+;\s*fastcgi_pass unix:/run/php/mail\.sock;~', $conf);
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

    /**
     * The $client_net map must give every address of one IPv6 /64 the same key and different /64s different
     * keys. This applies the map's own regular expressions (nginx uses PCRE too) to nginx's text form of
     * addresses of every shape: each of the 256 patterns of zero and non-zero groups.
     */
    public function testNginxKeysIpv6ClientsByTheirSlash64LikeTheApp(): void
    {
        $conf = self::read('docker/nginx/default.conf');
        self::assertSame(1, preg_match('~^map \$remote_addr \$client_net \{\n(.*?)\n\}~ms', $conf, $block));
        $rules = [];
        foreach (explode("\n", $block[1]) as $line) {
            $line = trim((string) preg_replace('~\s#.*$~', '', $line));
            self::assertSame(1, preg_match('~^("[^"]+"|\S+)\s+(\S+);$~', $line, $m), $line);
            $rules[] = [trim($m[1], '"'), $m[2]];
        }
        $key = static function (string $addr) use ($rules): string {
            foreach ($rules as [$pattern, $value]) {
                if ('default' === $pattern) {
                    return $addr;
                }
                if (1 === preg_match('#'.substr($pattern, 1).'#', $addr, $m)) {
                    return (string) preg_replace_callback('~\$(\w+)~', static fn ($v) => $v[1] === 'remote_addr' ? $addr : $m[$v[1]], $value);
                }
            }

            return $addr;
        };

        mt_srand(7);
        $seen = [];
        for ($pattern = 0; $pattern < 256; ++$pattern) {
            for ($n = 0; $n < 4; ++$n) {
                $groups = [];
                for ($i = 0; $i < 8; ++$i) {
                    $groups[] = ($pattern >> $i) & 1 ? mt_rand(1, 0xFFFF) : 0;
                }
                $packed = pack('n*', ...$groups);
                $net = implode(':', array_map('dechex', \array_slice($groups, 0, 4)));
                self::assertSame($net, $key(self::nginxText($packed)), self::nginxText($packed));
                // Another address in the same /64 (any interface id) gets the same key.
                $other = substr($packed, 0, 8).random_bytes(8);
                self::assertSame($net, $key(self::nginxText($other)), self::nginxText($other));
                self::assertSame(ClientIdentity::networkKey(inet_ntop($packed)), ClientIdentity::networkKey(inet_ntop($other)));
                $seen[$net] = true;
            }
        }
        self::assertGreaterThan(900, \count($seen), 'different networks keep different keys');
        self::assertSame('2001:db8:0:0', $key('2001:db8::1'));
        self::assertSame('2001:db8:0:5', $key('2001:db8:0:5::1'));
        self::assertSame('203.0.113.9', $key('203.0.113.9'));
        self::assertSame('203.0.113.9', $key('::ffff:203.0.113.9'), 'an IPv4-mapped client is its IPv4 address, as in the app');

        foreach (['api_client', 'auth_client'] as $map) {
            self::assertMatchesRegularExpression('~map \$uri \$'.$map.'\s+\{[^}]* \$client_net;~', $conf);
        }
        self::assertStringContainsString('limit_conn_zone $client_net zone=perip', $conf);
        self::assertStringNotContainsString('$binary_remote_addr', (string) preg_replace('~#[^\n]*~', '', $conf));
    }

    /**
     * Regression (0.7.0-beta.2 outage): the $client_net map's regex runs when limit_conn is checked, after the location
     * is chosen, and overwrote the $1 of the versioned-assets location, so every CSS and JS request was redirected to a
     * directory URL. Directives may use only named captures, which later regexes leave alone.
     */
    public function testNginxUsesNoNumberedCapturesThatALaterMapRegexCouldOverwrite(): void
    {
        foreach (['docker/nginx/default.conf', 'docker/nginx/app-fastcgi.conf'] as $file) {
            $conf = (string) preg_replace('~#[^\n]*~', '', self::read($file));
            self::assertDoesNotMatchRegularExpression('~\$[0-9]~', $conf, "$file uses a numbered capture");
        }
        self::assertStringContainsString('alias /app/public/assets/$asset;', self::read('docker/nginx/default.conf'));
    }

    /** One client network can never hold every PHP worker: its cap in PHP is below each pool's default size. */
    public function testNginxCapsOneClientsRequestsInPhpBelowThePoolSizes(): void
    {
        $conf = (string) preg_replace('~#[^\n]*~', '', self::read('docker/nginx/default.conf'));
        $compose = self::read('compose.yaml');
        self::assertSame(1, preg_match('~FPM_MAX_CHILDREN:-(\d+)~', $compose, $www));
        self::assertSame(1, preg_match('~FPM_MAIL_MAX_CHILDREN:-(\d+)~', $compose, $mail));
        // Every location that reaches PHP directly sets its own cap (the internal /index.php location never applies one).
        preg_match_all('~location ([^{]+)\{([^}]*)\}~', $conf, $locations, \PREG_SET_ORDER);
        $checked = 0;
        foreach ($locations as [, $match, $body]) {
            $php = str_contains($body, 'fastcgi_pass') || str_contains($body, '/index.php$is_args');
            if (!$php || str_contains($body, 'internal;') || str_contains($match, '/healthz')) {
                continue;
            }
            self::assertSame(1, preg_match('~limit_conn perip (\d+);~', $body, $cap), "location $match needs a per-client cap");
            $pool = str_contains($body, 'mail.sock') ? (int) $mail[1] : (int) $www[1];
            self::assertLessThan($pool, (int) $cap[1], "location $match");
            ++$checked;
        }
        self::assertSame(4, $checked, "/, /api/admin/ and the two email endpoints");
    }

    public function testBaseImagesArePinnedByDigest(): void
    {
        preg_match_all('~^FROM\s+(\S+)~m', self::read('Dockerfile'), $from);
        preg_match_all('~COPY --from=(\S+)~', self::read('Dockerfile'), $copy);
        $external = array_filter([...$from[1], ...$copy[1]], static fn (string $i) => str_contains($i, ':') || str_contains($i, '/'));
        self::assertNotEmpty($external);
        foreach ($external as $image) {
            self::assertMatchesRegularExpression('~^[a-z0-9./-]+:[\w.-]+@sha256:[0-9a-f]{64}$~', $image);
        }
        foreach (['compose.yaml', 'compose.dev.yaml', 'compose.staging.yaml', '.github/workflows/ci.yml'] as $file) {
            preg_match_all('~image:\s*(\S+)~', self::read($file), $m);
            foreach ($m[1] as $image) {
                if (!str_starts_with($image, '${') && !str_starts_with($image, 'neighborhood-events-map-app:')) {
                    self::assertMatchesRegularExpression('~@sha256:[0-9a-f]{64}$~', $image, "$file: $image");
                }
            }
        }
        self::assertStringContainsString('package-ecosystem: docker-compose', self::read('.github/dependabot.yml'), 'digests are kept current');
    }

    public function testTheMaintenanceContainerGetsNoMailOrRoutingCredentials(): void
    {
        $services = Yaml::parse(self::read('compose.yaml'))['services'];
        $env = $services['maintenance']['environment'];
        self::assertSame('null://null', $env['MAILER_DSN']);
        self::assertSame('', $env['ROUTING_API_KEY']);
        self::assertSame('', $env['ORGANIZER_EMAILS']);
        self::assertStringContainsString('POSTGRES_PASSWORD', $env['DATABASE_URL'], 'it still reaches the database');
        self::assertStringContainsString('MAILER_DSN', $services['app']['environment']['MAILER_DSN'], 'the app keeps them');
    }

    /** nginx's text form of an IPv6 address (ngx_inet6_ntop): the longest run of two or more zero groups (the first, on a tie) is "::", groups in lower-case hex without leading zeros, an IPv4 tail where nginx prints one. */
    private static function nginxText(string $packed): string
    {
        $g = array_values(unpack('n*', $packed));
        [$zero, $max, $run, $last] = [-1, 1, 0, -1];
        foreach ($g as $i => $v) {
            if (0 !== $v) {
                if ($max < $run) {
                    [$zero, $max] = [$last, $run];
                }
                $run = 0;
                continue;
            }
            if (0 === $run++) {
                $last = $i;
            }
        }
        if ($max < $run) {
            [$zero, $max] = [$last, $run];
        }
        $v4 = 0 === $zero && ((5 === $max && 0xFFFF === $g[5]) || 6 === $max || (7 === $max && 0 !== $g[7] && 1 !== $g[7]));
        $out = '';
        for ($i = 0; $i < ($v4 ? 6 : 8); ++$i) {
            if ($i === $zero) {
                $out .= 0 === $i ? '::' : ':';
                $i += $max - 1;
                continue;
            }
            $out .= dechex($g[$i]).($i < 7 && !($v4 && 5 === $i) ? ':' : '');
        }
        if ($v4) {
            $out .= (5 === $max ? '' : '').inet_ntop(substr($packed, 12));
        }

        return $out;
    }
}
