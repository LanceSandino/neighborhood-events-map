<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #11, #12 (owner edits only own home, out-of-candy visible promptly,
 * CSRF required, role restrictions, unauthorized admin actions fail).
 */
final class OwnerAuthorizationTest extends AppTestCase
{
    private function claim(string $email, int $addressId): void
    {
        $this->confirm($this->requestLink($email, ['address' => $this->byId($addressId), 'listing' => self::listing()]));
    }

    public function testOwnerUpdatesStatusAndPublicMapReflectsItImmediately(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('21', 'Pumpkin Ln', 40.003, -75.006, 'west');
        $this->claim('owner@example.test', $id);
        $csrf = $this->csrf();

        $r = $this->json('PATCH', '/api/me/homes/'.$id, ['status' => 'out_of_candy', 'start' => '18:00', 'end' => '20:00', 'nonfood' => true], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('out_of_candy', $r['home']['listing']['status']);
        $home = $this->json('GET', '/api/homes/'.$id)['home'];
        self::assertSame('out_of_candy', $home['status']);
        self::assertSame('18:00', $home['start']);
        self::assertTrue($home['nonfood']);

        // Unpublish hides it; republish shows it again.
        $this->json('PATCH', '/api/me/homes/'.$id, ['published' => false], ['X-CSRF-Token' => $csrf]);
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $this->json('PATCH', '/api/me/homes/'.$id, ['published' => true], ['X-CSRF-Token' => $csrf]);
        self::assertCount(1, $this->json('GET', '/api/homes')['homes']);

        $r = $this->json('PATCH', '/api/me/homes/'.$id, ['end' => '10:00'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code());
        self::assertArrayHasKey('end', $r['error']['fields']);
    }

    public function testCannotEditSomeoneElsesHomeOrSkipCsrf(): void
    {
        $this->configureCommunity();
        $mine = $this->addAddress('31', 'Pumpkin Ln', 40.003, -75.007, 'west');
        $theirs = $this->addAddress('33', 'Pumpkin Ln', 40.003, -75.008, 'west');
        $this->claim('them@example.test', $theirs);
        $this->client->getCookieJar()->clear();
        $this->claim('me@example.test', $mine);
        $csrf = $this->csrf();

        $this->json('PATCH', '/api/me/homes/'.$theirs, ['status' => 'done'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(404, $this->code());
        $this->json('DELETE', '/api/me/homes/'.$theirs, ['confirm' => 'RELEASE'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(404, $this->code());
        self::assertSame('active', $this->db()->fetchOne('SELECT status FROM listings WHERE address_id = ?', [$theirs]));

        $r = $this->json('PATCH', '/api/me/homes/'.$mine, ['status' => 'done']);
        self::assertSame(403, $this->code());
        self::assertSame('csrf_invalid', $r['error']['code']);
        $this->json('PATCH', '/api/me/homes/'.$mine, ['status' => 'done'], ['X-CSRF-Token' => 'forged']);
        self::assertSame(403, $this->code());

        // Cross-site Origin is blocked even with a valid token.
        $this->json('PATCH', '/api/me/homes/'.$mine, ['status' => 'done'], ['X-CSRF-Token' => $csrf, 'Origin' => 'https://evil.example']);
        self::assertSame('bad_origin', json_decode((string) $this->client->getResponse()->getContent(), true)['error']['code']);

        $me = $this->json('GET', '/api/me');
        self::assertSame(['31 Pumpkin Ln'], array_column($me['homes'], 'address'), 'dashboard shows only own homes');
    }

    public function testResidentCannotUseOrganizerApiAndAnonymousGets401(): void
    {
        $this->configureCommunity();
        $this->json('GET', '/api/admin/overview');
        self::assertSame(401, $this->code());
        $this->client->request('GET', '/admin');
        self::assertSame(302, $this->code());

        $this->signIn('resident@example.test');
        $csrf = $this->csrf();
        $this->json('GET', '/api/admin/overview');
        self::assertSame(403, $this->code());
        $this->json('POST', '/api/admin/geometries/approve', ['confirm' => true], ['X-CSRF-Token' => $csrf]);
        self::assertSame(403, $this->code());
        $this->client->request('GET', '/admin');
        self::assertSame(403, $this->code());
    }

    public function testReleaseRequiresConfirmationAndFreesTheAddress(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('41', 'Pumpkin Ln', 40.003, -75.009, 'west');
        $this->claim('leaver@example.test', $id);
        $csrf = $this->csrf();
        $this->json('DELETE', '/api/me/homes/'.$id, [], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code());
        $this->json('DELETE', '/api/me/homes/'.$id, ['confirm' => 'RELEASE'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        self::assertSame([false], $this->claimedFlags('41 Pumpkin Ln'), 'released home is available again');
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
    }

    public function testSignedInUserCanClaimAnotherHomeDirectly(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('51', 'Lantern Way', 40.004, -74.996, 'east');
        $this->signIn('multi@example.test');
        $csrf = $this->csrf();
        $r = $this->json('POST', '/api/me/homes', ['address' => $this->byId($a), 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        $this->json('POST', '/api/me/homes', ['address' => $this->byId($a), 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), 'idempotent: already yours');
    }
}
