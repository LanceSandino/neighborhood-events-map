<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/EventTypesAcceptanceTest
 * @description Release-blocking acceptance items of the event-types roadmap that no other test pinned
 *   (docs/quality/event-types-acceptance.md): giving a home up, an organizer's unclaim and account erasure remove the
 *   address's listings of EVERY event, archived ones included, while a transfer keeps them with the address; a change
 *   to the active event's listing (resident edit, status, visibility, organizer hide and unhide) and the retention
 *   purge of an archived event never touch another event's listing; ending an event hides its homes at once, also by
 *   id; route plans take only the current event's public homes; another type's status and a "List for" without the
 *   CSRF token are refused; organizer-written event names and rules are escaped on every page; a lights home keeps
 *   its own hours on the nights it chose.
 * @dependencies tests/Support/AppTestCase
 */
final class EventTypesAcceptanceTest extends AppTestCase
{
    private string $csrf = '';

    /** @return array<string,string> */
    private function h(): array
    {
        return ['X-CSRF-Token' => $this->csrf];
    }

    /** The same few accounts sign in again and again here; the sign-in limits have their own tests. */
    private function freshLimits(): void
    {
        $this->db()->executeStatement('DELETE FROM throttle_events');
    }

    private function organizer(): void
    {
        $this->freshLimits();
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now()) ON CONFLICT (email_normalized) DO UPDATE SET is_admin = TRUE");
        $this->client->getCookieJar()->clear();
        $this->signIn('org@example.test');
        $this->csrf = $this->csrf();
    }

    private function resident(string $email): string
    {
        $this->freshLimits();
        $this->client->getCookieJar()->clear();
        $this->signIn($email);

        return $this->csrf();
    }

    /** Lists a new address for the event on the map through a confirmed claim request. */
    private function listHome(string $house, string $email, ?array $listing = null, float $lon = -75.005): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, $lon, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => $listing ?? self::listing()]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }

    /** Prepares a Christmas lights season and puts it on the map in place of the current event. */
    private function switchToLights(array $settings = []): int
    {
        $this->organizer();
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03',
            'default_start' => '18:00', 'default_end' => '22:00', 'settings' => $settings], $this->h())['event'];
        self::assertSame(201, $this->code(), json_encode($draft));
        $active = $this->json('GET', '/api/admin/events')['active_id'];
        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true, 'replaces' => $active], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));

        return (int) $draft['id'];
    }

    /** Lists a claimed home for the lights season from My house. */
    private function listForLights(string $email, int $home, string $note = 'Inflatables on the lawn', array $windows = [['2026-12-19', '18:00', '22:00']]): void
    {
        $csrf = $this->resident($email);
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['note' => $note, 'details' => ['styles' => ['inflatables']],
            'windows' => array_map(static fn (array $w) => ['date' => $w[0], 'start' => $w[1], 'end' => $w[2]], $windows)], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
    }

    private function listingsOf(int $home): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings WHERE address_id = ?', [$home]);
    }

    public function testReleaseUnclaimAndErasureRemoveEveryEventsListingsWhileATransferKeepsThem(): void
    {
        $this->configureCommunity();
        $alice = $this->listHome('11', 'alice@example.test');
        $bob = $this->listHome('13', 'bob@example.test');
        $carol = $this->listHome('15', 'carol@example.test');
        $this->switchToLights();
        foreach (['alice@example.test' => $alice, 'bob@example.test' => $bob, 'carol@example.test' => $carol] as $email => $home) {
            $this->listForLights($email, $home);
            self::assertSame(2, $this->listingsOf($home), 'last Halloween (archived) and this lights season');
        }

        // The resident gives the home up: both seasons' listings go, the legacy row too.
        $csrf = $this->resident('alice@example.test');
        $this->json('DELETE', "/api/me/homes/$alice", ['confirm' => 'RELEASE'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        self::assertSame([0, 0], [$this->listingsOf($alice), (int) $this->db()->fetchOne('SELECT COUNT(*) FROM listings WHERE address_id = ?', [$alice])], 'no archived listing outlives the claim');

        // Account erasure on request.
        $app = new Application(static::$kernel ?? static::bootKernel());
        $erase = new CommandTester($app->find('app:user:delete'));
        self::assertSame(0, $erase->execute(['email' => 'bob@example.test', '--confirm' => true]), $erase->getDisplay());
        self::assertSame(0, $this->listingsOf($bob), 'erasure removes every season\'s listing');

        // A transfer moves the claim with its listings to the new owner; the old owner reaches nothing.
        $this->organizer();
        $r = $this->json('POST', "/api/admin/claims/$carol/transfer", ['confirm' => true, 'email' => 'dave@example.test'], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(2, $this->listingsOf($carol), 'the address keeps its seasons');
        $csrf = $this->resident('carol@example.test');
        $this->json('PATCH', "/api/me/homes/$carol", ['note' => 'Still mine?'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(404, $this->code());
        $this->resident('dave@example.test');
        self::assertSame('Inflatables on the lawn', $this->json('GET', '/api/me')['homes'][0]['listing']['note'], 'the new owner manages this season\'s listing');

        // The organizer's unclaim removes them all.
        $this->organizer();
        $this->json('POST', "/api/admin/claims/$carol/unclaim", ['confirm' => true, 'reason' => 'Moved away'], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame(0, $this->listingsOf($carol));
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
    }

    public function testChangesToTheActiveListingAndTheRetentionPurgeNeverTouchAnotherEventsListing(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('21', 'erin@example.test', self::listing(['note' => 'Full-size bars']));
        $halloween = (int) $this->json('GET', '/api/event')['event']['id'];
        $this->switchToLights();
        // Own hours on the nights chosen, not the season's 6–10 PM.
        $this->listForLights('erin@example.test', $home, 'Inflatables', [['2026-12-19', '17:00', '21:30'], ['2026-12-31', '19:00', '23:00']]);
        self::assertSame([['date' => '2026-12-19', 'start' => '17:00', 'end' => '21:30'], ['date' => '2026-12-31', 'start' => '19:00', 'end' => '23:00']],
            $this->json('GET', "/api/homes/$home")['home']['windows'], 'a lights home keeps its own hours');
        $archived = fn () => $this->db()->fetchAssociative('SELECT details, status, status_date, note, published, moderation_hidden, moderation_reason, published_at, updated_at FROM event_listings WHERE event_id = ? AND address_id = ?', [$halloween, $home]);
        $before = $archived();
        self::assertSame('Full-size bars', $before['note']);

        $csrf = $this->resident('erin@example.test');
        foreach ([['status' => 'off_tonight'], ['note' => 'Changed for the lights'], ['published' => false], ['published' => true]] as $patch) {
            $r = $this->json('PATCH', "/api/me/homes/$home", $patch, ['X-CSRF-Token' => $csrf]);
            self::assertSame(200, $this->code(), json_encode($r));
        }
        $this->organizer();
        foreach (['hide', 'unhide'] as $action) {
            $this->json('POST', "/api/admin/listings/$home/$action", ['reason' => 'Check'], $this->h());
            self::assertSame(200, $this->code());
        }
        self::assertSame($before, $archived(), 'last season\'s listing is exactly as it was');

        // Retention: deleting the archived season's listings leaves this season's alone.
        $this->json('POST', "/api/admin/events/$halloween/purge-listings", ['confirm' => 'DELETE'], $this->h());
        self::assertSame(200, $this->code());
        self::assertFalse($archived());
        self::assertSame('Changed for the lights', $this->json('GET', "/api/homes/$home")['home']['note']);

        // Ending the season hides its homes at once, by id too.
        $lights = (int) $this->json('GET', '/api/admin/events')['active_id'];
        $this->json('POST', "/api/admin/events/$lights/archive", ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->clear();
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $this->json('GET', "/api/homes/$home");
        self::assertSame(404, $this->code(), 'an ended event\'s home is not readable by id');
    }

    public function testRoutePlansTakeOnlyTheCurrentEventsPublicHomes(): void
    {
        $this->configureCommunity(['routing' => ['groups' => [['west', 'east']], 'provider_enabled' => true, 'crossings_verified' => true]]);
        $a = $this->listHome('31', 'a@example.test', null, -75.008);
        $b = $this->listHome('33', 'b@example.test', null, -75.006);
        $past = $this->listHome('35', 'c@example.test', null, -75.007); // listed for Halloween only
        $this->switchToLights();
        $this->listForLights('a@example.test', $a);
        $this->listForLights('b@example.test', $b);
        $unclaimed = $this->addAddress('37', 'Pumpkin Ln', 40.0043, -75.0065, 'west');
        $this->client->getCookieJar()->clear();

        $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b]]);
        self::assertSame(200, $this->code(), json_encode($r));
        foreach (['last season only' => $past, 'never listed' => $unclaimed, 'unknown' => 999999] as $why => $other) {
            $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $other]]);
            self::assertSame(422, $this->code(), $why);
            self::assertSame('One of those homes is not listed.', $r['error']['fields']['stops'] ?? null, $why);
        }
        $csrf = $this->resident('b@example.test');
        $this->json('PATCH', "/api/me/homes/$b", ['published' => false], ['X-CSRF-Token' => $csrf]);
        $this->client->getCookieJar()->clear();
        $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b]]);
        self::assertSame(422, $this->code(), 'a hidden listing is not routable');
    }

    public function testAnotherTypesStatusAndAListForWithoutTheCsrfTokenAreRefused(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('41', 'fay@example.test');
        $this->switchToLights();
        $csrf = $this->resident('fay@example.test');
        $this->json('POST', "/api/me/homes/$home/listing", ['details' => ['styles' => ['classic']], 'windows' => [['date' => '2026-12-19', 'start' => '18:00', 'end' => '22:00']]]);
        self::assertSame(403, $this->code(), '"List for" needs the CSRF token');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id WHERE o.state = \'active\''));
        $this->listForLights('fay@example.test', $home);
        $csrf = $this->resident('fay@example.test');
        foreach (['out_of_candy', 'sold_out', 'done', 'bogus'] as $status) {
            $r = $this->json('PATCH', "/api/me/homes/$home", ['status' => $status], ['X-CSRF-Token' => $csrf]);
            self::assertSame(422, $this->code(), $status.' is not a lights status');
            self::assertArrayHasKey('status', $r['error']['fields'] ?? [], $status);
        }
        self::assertSame('active', $this->json('GET', "/api/homes/$home")['home']['status']);
    }

    public function testOrganizerWrittenEventNamesAndRulesAreEscapedOnEveryPage(): void
    {
        $this->configureCommunity();
        $this->organizer();
        $name = '<script>alert(1)</script> Lights';
        $rule = '<img src=x onerror=alert(2)> Park on one side';
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'name' => $name, 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03',
            'default_start' => '18:00', 'default_end' => '22:00', 'settings' => ['rules' => [$rule], 'quiet_hours' => '<b>Quiet</b> after 9']], $this->h())['event'];
        self::assertSame(201, $this->code(), json_encode($draft));
        $preview = (string) $this->client->request('GET', '/admin/events/'.$draft['id'].'/preview')->html();
        $active = $this->json('GET', '/api/admin/events')['active_id'];
        $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true, 'replaces' => $active], $this->h());
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->clear();
        self::assertSame($name, $this->json('GET', '/api/event')['event']['name'], 'stored as typed');
        foreach (['/', '/about', '/privacy', '/terms', '/accessibility', '/login', 'preview'] as $path) {
            $html = 'preview' === $path ? $preview : (string) $this->client->request('GET', $path)->html();
            self::assertStringNotContainsString('<script>alert(1)', $html, $path);
            self::assertStringNotContainsString('<img src=x', $html, $path);
            self::assertStringNotContainsString('<b>Quiet</b>', $html, $path);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Lights', $html, $path.': the name, escaped');
        }
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt; Park on one side', (string) $this->client->request('GET', '/about')->html(), 'the rule, escaped');
    }
}
