<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Traceability: public-readiness hardening note "there is no sign out everywhere". Two signed-in devices are two
 * session cookies of one browser client; choosing "Sign out everywhere" on one ends both, server-side.
 */
final class SignOutEverywhereTest extends AppTestCase
{
    /** Signs in on a fresh "device" and returns its session cookie. */
    private function device(string $email): Cookie
    {
        $this->client->getCookieJar()->clear();
        $this->signIn($email);
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code());

        return $this->client->getCookieJar()->get('MOCKSESSID') ?? throw new \LogicException('no session cookie');
    }

    private function use(Cookie $device): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($device);
    }

    public function testSignOutEverywhereEndsEverySessionOfThatAccountAndNoOtherOne(): void
    {
        $this->configureCommunity();
        $phone = $this->device('resident@example.test');
        $laptop = $this->device('resident@example.test');
        $neighbor = $this->device('neighbor@example.test');

        $this->use($phone);
        $this->json('POST', '/api/me/sign-out-everywhere');
        self::assertSame(403, $this->code(), 'a CSRF-checked write');
        $this->json('POST', '/api/me/sign-out-everywhere', null, ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(200, $this->code());
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code(), 'this device is signed out');

        $this->use($laptop);
        $r = $this->json('GET', '/api/me');
        self::assertSame(401, $this->code(), 'and the other one too');
        self::assertStringContainsString('signed out on every device', $r['error']['message']);
        $this->client->request('GET', '/me');
        self::assertSame(302, $this->code(), 'pages send it to the sign-in page');

        $this->use($neighbor);
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code(), 'other accounts are untouched');

        $actions = $this->db()->fetchFirstColumn("SELECT action FROM audit_events WHERE action LIKE 'auth.s%' ORDER BY id");
        self::assertSame(['auth.signed_out_everywhere', 'auth.session_ended'], $actions);

        $this->clock->sleep(5);
        $this->device('resident@example.test'); // signing in again works as usual
    }

    public function testOrganizersCanSignAnAccountOutFromTheCommandLine(): void
    {
        $this->configureCommunity();
        $phone = $this->device('lost-phone@example.test');
        $t = new CommandTester((new Application(static::$kernel ?? static::bootKernel()))->find('app:user:sign-out'));
        $t->execute(['email' => 'Lost-Phone@example.test']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $this->use($phone);
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code());
        $t->execute(['email' => 'nobody@example.test']);
        self::assertSame(1, $t->getStatusCode());
    }
}
