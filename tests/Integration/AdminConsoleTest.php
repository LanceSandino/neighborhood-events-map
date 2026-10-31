<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Traceability: OPEN_SOURCE_AND_RELEASES (config-only branding/zones/logo), GEOGRAPHY (file import),
 * BLUEPRINT §4/§7 (address add/edit/import screens), admin pages render for organizers only.
 */
final class AdminConsoleTest extends AppTestCase
{
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCommunity();
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now())");
        $this->signIn('org@example.test');
        $this->csrf = $this->csrf();
    }

    private function upload(string $name, string $content, string $type): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $type, null, true);
    }

    public function testEveryConsolePageRenders(): void
    {
        foreach (['/admin' => 'Overview', '/admin/community' => 'Community & branding', '/admin/community/map' => 'Boundary & zones',
            '/admin/addresses' => 'Addresses & claims', '/admin/disputes' => 'Review requests', '/admin/audit' => 'Audit log'] as $url => $title) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->code(), $url);
            self::assertSame($title, $crawler->filter('h1')->text());
            self::assertNotEmpty($crawler->filter('meta[name="csrf-token"]')->attr('content'));
        }
        $o = $this->json('GET', '/api/admin/overview');
        self::assertTrue($o['boundary']['approved']);
        self::assertSame([], $o['boundary']['zones_without_geometry']);
    }

    public function testSettingsUpdateValidatesAndRebrandsWithoutCodeChanges(): void
    {
        $s = $this->json('GET', '/api/admin/community')['settings'];
        $s['community']['name'] = 'Lakeside Commons';
        $s['branding']['primary_color'] = '#2255AA';
        $s['zones'][] = ['key' => 'lakefront', 'label' => 'Lakefront'];
        $r = $this->json('PUT', '/api/admin/community', ['settings' => $s], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('Lakeside Commons', $this->json('GET', '/api/community')['community']['name']);
        $this->client->request('GET', '/theme.css');
        self::assertStringContainsString('#2255AA', (string) $this->client->getResponse()->getContent());
        self::assertSame(['lakefront'], $this->json('GET', '/api/admin/overview')['boundary']['zones_without_geometry']);

        $s['community']['timezone'] = 'Nowhere/Land';
        $r = $this->json('PUT', '/api/admin/community', ['settings' => $s], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code());
        self::assertSame('invalid_config', $r['error']['code']);
        $this->json('PUT', '/api/admin/community', [], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code());
    }

    public function testLogoUploadIsValidatedAndServedSandboxed(): void
    {
        $bad = $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'image/svg+xml');
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf], ['logo' => $bad]);
        self::assertSame(422, $this->code());
        $txt = $this->upload('logo.txt', 'hello', 'text/plain');
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf], ['logo' => $txt]);
        self::assertSame(422, $this->code());

        $ok = $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="orange"/></svg>', 'image/svg+xml');
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf], ['logo' => $ok]);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $url = $this->json('GET', '/api/community')['branding']['logo_url'];
        self::assertMatchesRegularExpression('~^/branding/logo\.svg\?v=[0-9a-f]{10}$~', $url, 'versioned so a new logo is never served from a cache');
        $replaced = $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="3" fill="purple"/></svg>', 'image/svg+xml');
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf], ['logo' => $replaced]);
        self::assertNotSame($url, $this->json('GET', '/api/community')['branding']['logo_url'], 'a replaced logo gets a new URL');
        $this->client->request('GET', '/branding/logo.svg');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $this->client->request('GET', '/branding/logo.php');
        self::assertSame(404, $this->code());

        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf, 'remove' => '1']);
        self::assertNull($this->json('GET', '/api/community')['branding']['logo_url']);
    }

    public function testAddressesAddEditSearchAndCsvUpload(): void
    {
        $r = $this->json('POST', '/api/admin/addresses', ['address' => '77 Lantern Way', 'lat' => 40.005, 'lon' => -74.995, 'zone_key' => 'east'], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        $this->json('POST', '/api/admin/addresses', ['address' => '77 Lantern Way', 'lat' => 40.005, 'lon' => -74.995], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code(), 'duplicate');
        $this->json('POST', '/api/admin/addresses', ['address' => '79 Lantern Way'], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code(), 'coordinates required, never geocoded');
        $this->json('POST', '/api/admin/addresses', ['address' => '81 Lantern Way', 'lat' => 40.005, 'lon' => -74.995, 'zone_key' => 'phase-5'], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code());

        $this->json('PATCH', '/api/admin/addresses/'.$r['id'], ['lat' => 40.5, 'lon' => -74.995, 'enabled' => false], ['X-CSRF-Token' => $this->csrf]);
        $row = $this->json('GET', '/api/admin/addresses?q=77 lantern')['addresses'][0];
        self::assertFalse($row['inside_boundary']);
        self::assertFalse($row['enabled']);
        self::assertSame(1, $this->json('GET', '/api/admin/overview')['counts']['addresses_outside_boundary']);

        $csv = $this->upload('a.csv', "house_number,street,unit,latitude,longitude,zone_key\n5,Pumpkin Ln,,40.005,-75.005,west\n6,Pumpkin Ln,B,40.004,-75.004,\n", 'text/csv');
        $res = $this->json('POST', '/api/admin/addresses/import', null, ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code(), 'file required');
        $this->client->request('POST', '/api/admin/addresses/import', ['_csrf' => $this->csrf, 'dry_run' => '1'], ['file' => $csv]);
        self::assertSame(2, json_decode((string) $this->client->getResponse()->getContent(), true)['report']['imported']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM eligible_addresses'));
        $csv2 = $this->upload('a.csv', "house_number,street,unit,latitude,longitude,zone_key\n5,Pumpkin Ln,,40.005,-75.005,west\n6,Pumpkin Ln,B,40.004,-75.004,\n", 'text/csv');
        $this->client->request('POST', '/api/admin/addresses/import', ['_csrf' => $this->csrf], ['file' => $csv2]);
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM eligible_addresses'));
        self::assertSame('6 Pumpkin Ln Unit B', $this->db()->fetchOne("SELECT display_address FROM eligible_addresses WHERE unit = 'B'"));
        $bad = $this->upload('b.csv', "name\nx\n", 'text/csv');
        $this->client->request('POST', '/api/admin/addresses/import', ['_csrf' => $this->csrf], ['file' => $bad]);
        self::assertSame(422, $this->code());
    }

    public function testGeoJsonFileImportReplacesGeometryAndResetsApproval(): void
    {
        $file = $this->upload('b.geojson', (string) json_encode(['type' => 'Polygon', 'coordinates' => [[[-75.01, 40.0], [-74.99, 40.0], [-74.99, 40.02], [-75.01, 40.02], [-75.01, 40.0]]]]), 'application/geo+json');
        $this->client->request('POST', '/api/admin/geometries/import', ['_csrf' => $this->csrf], ['file' => $file]);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $g = $this->json('GET', '/api/admin/geometries');
        self::assertFalse($g['approved']);
        self::assertCount(1, $g['geojson']['features']);
        $r = $this->json('POST', '/api/admin/geometries/approve', ['confirm' => true, 'action' => 'revoke'], ['X-CSRF-Token' => $this->csrf]);
        self::assertFalse($r['approved']);
        $r = $this->json('POST', '/api/admin/registration', ['enabled' => false], ['X-CSRF-Token' => $this->csrf]);
        self::assertFalse($r['registration']['open']);
    }

    public function testTheConsoleShowsTheEventTypeAndOnlyOffersEnabledTypes(): void
    {
        $r = $this->json('GET', '/api/admin/community');
        self::assertSame('halloween', $r['settings']['event']['type']);
        self::assertSame([['key' => 'halloween', 'name' => 'Halloween'], ['key' => 'holiday_lights', 'name' => 'Christmas Lights'], ['key' => 'garage_sale', 'name' => 'Garage Sale']],
            array_map(static fn (array $t) => ['key' => $t['key'], 'name' => $t['name']], $r['event_types']));
        self::assertSame('halloween', $r['event_type']['key']);
        self::assertSame(['candy', 'nonfood_treats', 'adult_refreshments', 'sensory_filters'], array_column($r['event_type']['features'], 'key'));
        self::assertSame('Trick-or-treat reminders (one per line, up to 12)', $r['event_type']['copy']['reminders_label']);
        $s = $r['settings'];
        $s['event']['type'] = 'fireworks';
        $refused = $this->json('PUT', '/api/admin/community', ['settings' => $s], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code(), json_encode($refused));
        self::assertSame(['event.type "fireworks" is not a known event type (halloween, holiday_lights, garage_sale).'], $refused['error']['problems']);
        foreach (['holiday_lights', 'garage_sale'] as $other) {
            $s['event']['type'] = $other;
            $refused = $this->json('PUT', '/api/admin/community', ['settings' => $s], ['X-CSRF-Token' => $this->csrf]);
            self::assertSame(422, $this->code(), 'the current event\'s type never changes here: '.json_encode($refused));
            self::assertStringContainsString('event.type cannot change for the current event', $refused['error']['problems'][0]);
        }
        self::assertSame('halloween', $this->json('GET', '/api/event')['event']['type'], 'nothing was saved');
    }
}
