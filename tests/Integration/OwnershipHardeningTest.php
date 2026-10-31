<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\AdminPageController;
use App\Controller\Api\AdminApiController;
use App\Controller\Api\MeApiController;
use App\Listing\ListingDraft;
use App\Listing\ResidentHomes;
use App\Security\UserRepository;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Traceability: security hardening (docs/security/hardening-report.md) — residents manage a bounded
 * number of homes; organizer moderation survives release/re-claim; the confirmation page shows what
 * will be published; roles are declared in code as well as in access_control; organizers may be
 * listed in configuration; resident data is reached only through the owner-scoped ResidentHomes.
 */
final class OwnershipHardeningTest extends AppTestCase
{
    private function residentClaims(string $email, int ...$addressIds): string
    {
        $this->client->getCookieJar()->clear();
        $this->signIn($email);
        $csrf = $this->csrf();
        foreach ($addressIds as $id) {
            $r = $this->json('POST', '/api/me/homes', ['address' => $this->byId($id), 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
            self::assertSame(201, $this->code(), json_encode($r));
        }

        return $csrf;
    }

    private function organizerCsrf(): string
    {
        static::getContainer()->get(UserRepository::class)->setAdmin('boss@example.test', true);
        $this->client->getCookieJar()->clear();
        $this->signIn('boss@example.test');

        return $this->csrf();
    }

    public function testEachResidentManagesAtMostTheConfiguredNumberOfHomes(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('51', 'Lantern Way', 40.004, -74.996, 'east');
        $b = $this->addAddress('53', 'Lantern Way', 40.0045, -74.996, 'east');
        $c = $this->addAddress('55', 'Lantern Way', 40.005, -74.996, 'east');
        $csrf = $this->residentClaims('multi@example.test', $a, $b);
        $r = $this->json('POST', '/api/me/homes', ['address' => $this->byId($c), 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
        self::assertSame(409, $this->code());
        self::assertSame('home_limit', $r['error']['code']);
        self::assertStringContainsString('up to 2 homes', $r['error']['message']);
        self::assertFalse((bool) $this->db()->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$c]));

        // The organizer can raise the limit for the community.
        $settings = $this->repo()->current()->settings;
        $settings['registration']['max_homes_per_user'] = 3;
        $this->repo()->saveSettings($settings, null);
        $this->json('POST', '/api/me/homes', ['address' => $this->byId($c), 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code());
    }

    public function testTheLimitAlsoAppliesToEmailLinkClaims(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('51', 'Lantern Way', 40.004, -74.996, 'east');
        $b = $this->addAddress('53', 'Lantern Way', 40.0045, -74.996, 'east');
        $c = $this->addAddress('55', 'Lantern Way', 40.005, -74.996, 'east');
        $this->residentClaims('multi@example.test', $a, $b);
        $this->client->getCookieJar()->clear();
        $this->confirm($this->requestLink('multi@example.test', ['address' => $this->byId($c), 'listing' => self::listing()]));
        $me = $this->json('GET', '/api/me');
        self::assertSame('limit', $me['claim_outcome']['status']);
        self::assertCount(2, $me['homes']);
    }

    public function testDashboardClaimsAreRateLimitedPerResident(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('51', 'Lantern Way', 40.004, -74.996, 'east');
        $this->residentClaims('busy@example.test', $a);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM throttle_events WHERE bucket = 'claim_user'"));
    }

    public function testHiddenListingStaysHiddenAfterReleaseAndReclaim(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('61', 'Lantern Way', 40.005, -74.994, 'east');
        $this->residentClaims('owner@example.test', $id);
        $admin = $this->organizerCsrf();
        $this->json('POST', "/api/admin/listings/$id/hide", ['reason' => 'offensive note'], ['X-CSRF-Token' => $admin]);
        self::assertSame(200, $this->code());

        $this->client->getCookieJar()->clear();
        $this->signIn('owner@example.test');
        $csrf = $this->csrf();
        $this->json('DELETE', "/api/me/homes/$id", ['confirm' => 'RELEASE'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        $this->json('POST', '/api/me/homes', ['address' => $this->byId($id), 'listing' => self::listing(['note' => 'Back again'])], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code());
        self::assertSame([], $this->json('GET', '/api/homes')['homes'], 'release + re-claim does not undo the organizer\'s decision');
        self::assertTrue($this->json('GET', '/api/me')['homes'][0]['listing']['moderation_hidden']);

        $this->client->getCookieJar()->clear();
        $admin = $this->organizerCsrf();
        $this->json('POST', "/api/admin/listings/$id/unhide", [], ['X-CSRF-Token' => $admin]);
        self::assertCount(1, $this->json('GET', '/api/homes')['homes']);
    }

    public function testConfirmationPageShowsTheListingThatWillBePublished(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('7', 'Lantern Way', 40.005, -74.995, 'east');
        $token = $this->requestLink('neighbor@example.test', ['address' => $this->byId($id),
            'listing' => self::listing(['note' => 'Glow sticks at the gate', 'jump_scares' => true, 'scare_level' => 'intense'])]);
        $html = (string) $this->client->request('GET', '/auth/confirm?t='.$token)->html();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('7 Lantern Way', $html);
        self::assertStringContainsString('Glow sticks at the gate', $html, 'the owner sees the exact note before publishing it');
        self::assertStringContainsString('Big scares', $html, 'jump scares / intense setups are shown as big scares');
        self::assertStringContainsString('5:30', $html);
    }

    public function testOrganizersListedInConfigurationGetTheOrganizerRole(): void
    {
        $this->configureCommunity();
        $this->signIn('listed-organizer@example.test'); // ORGANIZER_EMAILS in phpunit.dist.xml
        $this->json('GET', '/api/admin/overview');
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->clear();
        $this->signIn('resident@example.test');
        $this->json('GET', '/api/admin/overview');
        self::assertSame(403, $this->code());
    }

    public function testOrganizerAndResidentControllersDeclareTheirRoleInCode(): void
    {
        $role = static function (string $class): ?string {
            $attrs = (new \ReflectionClass($class))->getAttributes(IsGranted::class);

            return [] === $attrs ? null : (string) $attrs[0]->newInstance()->attribute;
        };
        self::assertSame('ROLE_ADMIN', $role(AdminApiController::class));
        self::assertSame('ROLE_ADMIN', $role(AdminPageController::class));
        self::assertSame('ROLE_USER', $role(MeApiController::class));
    }

    public function testResidentHomesNeverReachAnotherResidentsHome(): void
    {
        $this->configureCommunity();
        $mine = $this->addAddress('51', 'Lantern Way', 40.004, -74.996, 'east');
        $theirs = $this->addAddress('61', 'Lantern Way', 40.005, -74.994, 'east');
        $this->residentClaims('a@example.test', $mine);
        $this->residentClaims('b@example.test', $theirs);
        $before = $this->db()->fetchAssociative('SELECT * FROM listings WHERE address_id = ?', [$theirs]);

        $beforeEvent = $this->db()->fetchAssociative('SELECT * FROM event_listings WHERE address_id = ?', [$theirs]);

        $users = static::getContainer()->get(UserRepository::class);
        $homes = static::getContainer()->get(ResidentHomes::class)->of($users->findByEmail('a@example.test'));
        $event = $this->repo()->current()->event;
        self::assertNotNull($event);
        self::assertSame([$mine], array_map(static fn (array $h) => (int) $h['id'], $homes->all($event->id)));
        self::assertNull($homes->find($theirs, $event->id));
        self::assertFalse($homes->owns($theirs));
        $draft = ListingDraft::fromInput(['note' => 'mine now'] + self::listing(), $this->repo()->current());
        $windows = ListingDraft::windowsFor($draft, $event, (new \App\Event\Type\HalloweenType()));
        self::assertFalse($homes->update($theirs, $event, $draft, $windows, null));
        self::assertTrue($homes->update($mine, $event, $draft, $windows, null), 'the same call works on the resident\'s own home');
        self::assertSame('not_found', $homes->create($theirs, $event, $draft, $windows, null), 'nor can it list their home for an event');
        self::assertSame('exists', $homes->create($mine, $event, $draft, $windows, null));
        self::assertSame('not_found', $homes->release($theirs));
        self::assertSame($before, $this->db()->fetchAssociative('SELECT * FROM listings WHERE address_id = ?', [$theirs]));
        self::assertSame($beforeEvent, $this->db()->fetchAssociative('SELECT * FROM event_listings WHERE address_id = ?', [$theirs]));
        self::assertNotFalse($this->db()->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$theirs]));
    }
}
