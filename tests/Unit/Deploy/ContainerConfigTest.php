<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Security\ClientIdentity;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Traceability: security audit run-1 leads 4, 9 and 15 (container configuration) and the public-readiness
 * hardening notes, and the October 2026 PHP advisories. Pins what the live checks showed: PHP-FPM has no TCP listener (FastCGI can set PHP settings
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
        self::assertSame(['/run/php/app.sock', '/run/php/mail.sock', '/run/php/upload.sock'], $m[1], 'no host:port listener');
        self::assertSame(3, preg_match_all('~^listen\.mode = 0660$~m', $pools));
        self::assertSame(3, preg_match_all('~^listen\.group = fcgi-nginx$~m', $pools));
        self::assertMatchesRegularExpression('~\[mail\][^\[]*pm\.max_children = \$\{FPM_MAIL_MAX_CHILDREN\}~', $pools);
        self::assertMatchesRegularExpression('~\[upload\][^\[]*pm\.max_children = \$\{FPM_UPLOAD_MAX_CHILDREN\}~', $pools);
        foreach (['app', 'mail', 'upload'] as $pool) {
            self::assertStringContainsString("/run/php/$pool.sock", self::read('docker/php/healthcheck.sh'), "the healthcheck pings the $pool pool");
        }
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
        self::assertSame(1, preg_match('~FPM_UPLOAD_MAX_CHILDREN:-(\d+)~', $compose, $upload));
        // Every location that reaches PHP directly sets its own cap (the internal /index.php location never applies one).
        preg_match_all('~location ([^{]+)\{([^}]*)\}~', $conf, $locations, \PREG_SET_ORDER);
        $checked = 0;
        foreach ($locations as [, $match, $body]) {
            $php = str_contains($body, 'fastcgi_pass') || str_contains($body, '/index.php$is_args');
            if (!$php || str_contains($body, 'internal;') || str_contains($match, '/healthz')) {
                continue;
            }
            self::assertSame(1, preg_match('~limit_conn perip (\d+);~', $body, $cap), "location $match needs a per-client cap");
            $pool = str_contains($body, 'mail.sock') ? (int) $mail[1] : (str_contains($body, 'upload.sock') ? (int) $upload[1] : (int) $www[1]);
            self::assertLessThan($pool, (int) $cap[1], "location $match");
            ++$checked;
        }
        self::assertSame(7, $checked, "/, /api/admin/, the two email endpoints and the three upload endpoints");
    }

    /**
     * CVE-2026-109618 / CVE-2026-109619 (PHP core remote code execution, fixed 21 October 2026 in 8.3.36, 8.4.27 and
     * 8.5.12): the tests never run, and the images never ship, an older PHP of a supported line. Session upload
     * progress, which the app never uses, stays off: it is PHP's mitigation for the second flaw and for
     * CVE-2026-115560 (23 October 2026, no fixed release yet), which also needs the serialize handler switched at
     * runtime. On PHP 8.5, fatal errors carry no backtraces (PHP's other partial mitigation for CVE-2026-109619),
     * and no per-directory .user.ini file can change a setting.
     */
    public function testPhpHasTheOctober2026SecurityFixesAndNoUploadProgress(): void
    {
        $fixed = ['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
        $line = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
        self::assertArrayHasKey($line, $fixed, 'a supported PHP line');
        self::assertTrue(version_compare(\PHP_VERSION, $fixed[$line], '>='), \PHP_VERSION.' lacks the October 2026 security fixes');
        $ini = self::read('docker/php/php.ini');
        self::assertMatchesRegularExpression('~^session\.upload_progress\.enabled = 0$~m', $ini);
        self::assertMatchesRegularExpression('~^session\.serialize_handler = php$~m', $ini);
        self::assertMatchesRegularExpression('~^fatal_error_backtraces = Off$~m', $ini);
        self::assertMatchesRegularExpression('~^user_ini\.filename =$~m', $ini);
        self::assertMatchesRegularExpression('~^max_file_uploads = 1$~m', $ini, 'each upload request carries one file');
        foreach (self::phpSources() as $file => $code) {
            self::assertDoesNotMatchRegularExpression('~ini_set\(\s*[\'"]session\.~', $code, "$file changes a session setting at runtime");
        }
    }

    /** Production images compile assertions out and keep argument values out of exception traces (php.ini-production). */
    public function testProductionPhpCompilesAssertionsOutAndHidesTraceArguments(): void
    {
        $prod = self::read('docker/php/php-prod.ini');
        self::assertMatchesRegularExpression('~^zend\.assertions = -1$~m', $prod);
        self::assertMatchesRegularExpression('~^zend\.exception_ignore_args = On$~m', $prod);
        self::assertMatchesRegularExpression('~^display_errors = Off$~m', self::read('docker/php/php.ini'));
        self::assertMatchesRegularExpression('~^expose_php = Off$~m', self::read('docker/php/php.ini'));
    }

    /**
     * Only the small "upload" pool accepts file uploads; the www and mail pools refuse them, so PHP's multipart
     * upload code (where session upload progress lives) runs for nobody but the three organizer upload endpoints.
     * No web worker can run a program or open a URL as a file; the CLI (maintenance, console) is unaffected.
     */
    public function testOnlyTheUploadPoolAcceptsFilesAndNoWebWorkerRunsPrograms(): void
    {
        $pools = self::pools();
        self::assertSame(['www', 'mail', 'upload'], array_keys($pools));
        foreach ($pools as $name => $body) {
            self::assertMatchesRegularExpression('~^php_admin_flag\[allow_url_fopen\] = off$~m', $body, "$name: allow_url_fopen");
            self::assertSame(1, preg_match('~^php_admin_value\[disable_functions\] = (\S+)$~m', $body, $m), "$name: disable_functions");
            $disabled = explode(',', $m[1]);
            foreach (['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen', 'pcntl_exec'] as $fn) {
                self::assertContains($fn, $disabled, "$name may not run programs ($fn)");
            }
            if ('upload' === $name) {
                self::assertDoesNotMatchRegularExpression('~file_uploads\] = off~', $body);
            } else {
                self::assertMatchesRegularExpression('~^php_admin_flag\[file_uploads\] = off$~m', $body, "$name refuses file uploads");
            }
        }
        self::assertMatchesRegularExpression('~\[upload\][^\[]*pm = ondemand~', self::read('docker/php/fpm-pool.conf'));
        self::assertStringContainsString('FPM_UPLOAD_MAX_CHILDREN=2', self::read('Dockerfile'));
    }

    /**
     * nginx refuses a multipart body (415) before PHP sees it, except on the three organizer upload endpoints, which
     * it sends to the upload pool. This applies the map's own regular expressions (nginx uses PCRE too).
     */
    public function testNginxSendsMultipartBodiesOnlyToTheUploadPool(): void
    {
        $conf = self::read('docker/nginx/default.conf');
        self::assertSame(1, preg_match('~^map \$content_type \$multipart_body \{([^}]*)\}~m', $conf, $mp));
        self::assertSame(1, preg_match('~^map "\$multipart_body:\$uri" \$refuse_multipart \{([^}]*)\}~m', $conf, $rm));
        $apply = static function (string $block, string $subject): string {
            foreach (explode("\n", trim($block)) as $line) {
                $line = trim((string) preg_replace('~\s#.*$~', '', $line));
                if ('' === $line) {
                    continue;
                }
                self::assertSame(1, preg_match('~^("[^"]+"|\S+)\s+(\S+);$~', $line, $m), $line);
                $pattern = trim($m[1], '"');
                if ('default' === $pattern) {
                    $default = $m[2];
                    continue;
                }
                $flags = str_starts_with($pattern, '~*') ? 'i' : '';
                if (1 === preg_match('#'.substr($pattern, '' === $flags ? 1 : 2).'#'.$flags, $subject)) {
                    return $m[2];
                }
            }

            return $default ?? '';
        };
        foreach (['multipart/form-data; boundary=x' => '1', 'Multipart/Form-Data;boundary=x' => '1', 'multipart/mixed' => '1',
            'application/json' => '0', 'application/x-www-form-urlencoded' => '0', '' => '0'] as $type => $want) {
            self::assertSame($want, $apply($mp[1], $type), "Content-Type: $type");
        }
        $uploads = ['/api/admin/community/logo', '/api/admin/geometries/import', '/api/admin/addresses/import'];
        foreach ($uploads as $uri) {
            self::assertSame('0', $apply($rm[1], "1:$uri"), "$uri takes multipart bodies");
            self::assertMatchesRegularExpression('~location = '.preg_quote($uri, '~').' \{[^}]*fastcgi_pass unix:/run/php/upload\.sock;~', $conf);
        }
        foreach (['/api/auth/request-link', '/api/disputes', '/api/admin/community', '/api/admin/geometries', '/api/me/homes', '/', '/api/admin/community/logo/x'] as $uri) {
            self::assertSame('1', $apply($rm[1], "1:$uri"), "$uri refuses multipart bodies");
            self::assertSame('0', $apply($rm[1], "0:$uri"), "$uri takes other bodies");
        }
        self::assertMatchesRegularExpression('~if \(\$refuse_multipart\) \{\s*return 415;\s*\}~', $conf);
        preg_match_all('~fastcgi_pass unix:/run/php/upload\.sock;~', $conf, $m);
        self::assertCount(3, $m[0], 'nothing else reaches the upload pool');
    }

    /**
     * The pinned base images can trail Alpine's security fixes by days (brotli 1.2.1, CVE-2026-111497, shipped before
     * the PHP and nginx images were rebuilt), so both runtime images run `apk upgrade` at build time, and CI scans
     * the built images for any fixed HIGH/CRITICAL package (Trivy).
     */
    public function testRuntimeImagesTakeAlpineSecurityFixesAndAreScanned(): void
    {
        $docker = self::read('Dockerfile');
        foreach (['php-base', 'nginx'] as $stage) {
            self::assertSame(1, preg_match('~^FROM \S+ AS '.$stage.'\n(.*?)(?=^FROM |\z)~ms', $docker, $m), $stage);
            self::assertStringContainsString('apk upgrade --no-cache', $m[1], "$stage takes Alpine security fixes");
        }
        $ci = self::read('.github/workflows/ci.yml');
        self::assertMatchesRegularExpression('~--severity HIGH,CRITICAL --ignore-unfixed --exit-code 1~', $ci);
        $daily = (string) preg_replace('~\s*\\\\\n\s*~', ' ', self::read('.github/workflows/security.yml')); // join continued lines
        self::assertMatchesRegularExpression('~--severity HIGH,CRITICAL --ignore-unfixed --exit-code 1~', $daily);
        self::assertStringContainsString("cron: '17 7 * * *'", self::read('.github/workflows/security.yml'), 'the published images are re-checked daily');
    }

    /** @return array<string, string> pool name => its section of docker/php/fpm-pool.conf */
    private static function pools(): array
    {
        $conf = (string) preg_replace('~^;[^\n]*\n~m', '', self::read('docker/php/fpm-pool.conf'));
        preg_match_all('~^\[(\w+)\]\n(.*?)(?=^\[|\z)~ms', $conf, $m, \PREG_SET_ORDER);

        return array_combine(array_column($m, 1), array_column($m, 2));
    }

    /** @return iterable<string, string> */
    private static function phpSources(): iterable
    {
        $root = \dirname(__DIR__, 3);
        foreach (['src', 'config', 'public'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (str_ends_with((string) $file, '.php')) {
                    yield substr((string) $file, \strlen($root) + 1) => (string) file_get_contents((string) $file);
                }
            }
        }
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
        // Tools the workflows run as containers (the image scanner, composer) are pinned the same way
        // (setup-php's `tools: composer:v2` is not an image).
        foreach (['.github/workflows/ci.yml', '.github/workflows/security.yml'] as $file) {
            preg_match_all('~\b(aquasec/trivy:[\w.-]+|composer:\d[\w.-]*)(@sha256:[0-9a-f]{64})?~', self::read($file), $m, \PREG_SET_ORDER);
            self::assertNotEmpty($m, "$file runs the image scanner");
            foreach ($m as $ref) {
                self::assertArrayHasKey(2, $ref, "$file: {$ref[1]} is pinned by digest");
            }
        }
    }

    /** PostgreSQL 18+ images keep data under /var/lib/postgresql/<major>; the app's serverVersion matches the image. */
    public function testThePostgresImageVolumeAndServerVersionAgree(): void
    {
        $compose = Yaml::parse(self::read('compose.yaml'));
        self::assertSame(1, preg_match('~^postgres:(\d+)-alpine@~', $compose['services']['postgres']['image'], $m));
        self::assertGreaterThanOrEqual(18, (int) $m[1]);
        self::assertSame($compose['services']['postgres']['image'], $compose['services']['backup']['image'], 'pg_dump matches the server');
        self::assertContains('postgres-data:/var/lib/postgresql', $compose['services']['postgres']['volumes']);
        self::assertStringContainsString('serverVersion='.$m[1].'&', $compose['x-app-env']['DATABASE_URL'] ?? $compose['services']['app']['environment']['DATABASE_URL']);
        self::assertStringContainsString('serverVersion='.$m[1].'&', self::read('.env.dist'));
        self::assertStringContainsString('postgres:'.$m[1].'-alpine@', self::read('.github/workflows/ci.yml'));
        self::assertTrue(is_executable(\dirname(__DIR__, 3).'/bin/upgrade-postgres.sh'));
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
