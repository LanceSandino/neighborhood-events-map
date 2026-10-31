<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityBootstrapper;
use App\Community\ConfigException;
use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/EventBootstrapTest
 * @description community.yaml creates the install's first event (D-20261031-07): event.type and event.date, or
 *   starts_on/ends_on, become the first, active occurrence; a forced re-import updates that event instead of adding
 *   another; a one-day type keeps one day. event.settings (a sale's days, categories and early-bird time, the shared
 *   rules and route modes) are checked like Organizer → Events checks them and applied to the first event only; a
 *   refused setting imports nothing; a sale without settings takes its days. The commented reference configuration of
 *   each type (config/community.example.yaml, examples/reference/*.community.yaml) imports as it says.
 * @dependencies tests/Support/AppTestCase, Community/CommunityBootstrapper
 */
final class EventBootstrapTest extends AppTestCase
{
    public function testTheYamlCreatesTheFirstEventAndAForcedReimportUpdatesIt(): void
    {
        $file = $this->yaml("event:\n  type: halloween\n  name: Porch Light Night\n  starts_on: '2027-10-30'\n  ends_on: '2027-11-02'\n  starts_at: '16:00'\n  ends_at: '19:30'\n");
        $bootstrapper = static::getContainer()->get(CommunityBootstrapper::class);
        self::assertSame('imported', $bootstrapper->bootstrap($file)['status']);
        $rows = $this->db()->fetchAllAssociative('SELECT type, name, starts_on, ends_on, default_start_local, default_end_local, state FROM event_occurrences');
        self::assertSame([['type' => 'halloween', 'name' => 'Porch Light Night', 'starts_on' => '2027-10-30', 'ends_on' => '2027-10-30',
            'default_start_local' => '16:00:00', 'default_end_local' => '19:30:00', 'state' => 'active']], $rows, 'a Halloween is one day');
        $this->repo()->reset();
        self::assertSame(['2027-10-30', 'Porch Light Night', 'halloween'], [$this->json('GET', '/api/event')['event']['date'], $this->json('GET', '/api/event')['event']['name'], $this->json('GET', '/api/event')['event']['type']]);

        self::assertSame('already-configured', $bootstrapper->bootstrap($file)['status']);
        $again = $this->yaml("event:\n  date: '2027-10-31'\n  name: Halloween Night\n");
        self::assertSame('imported', $bootstrapper->bootstrap($again, true)['status']);
        self::assertSame([['name' => 'Halloween Night', 'starts_on' => '2027-10-31', 'state' => 'active']], $this->db()->fetchAllAssociative('SELECT name, starts_on, state FROM event_occurrences'), 'the same event, updated (nobody is listed yet)');
        unlink($file);
        unlink($again);
    }

    public function testTheCommentedReferenceConfigsOfEachTypeImport(): void
    {
        $bootstrapper = static::getContainer()->get(CommunityBootstrapper::class);
        $expect = [
            'config/community.example.yaml' => ['halloween', 'Halloween', '2026-10-31', '2026-10-31', []],
            'examples/reference/holiday-lights.community.yaml' => ['holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', ['best_night', 'quiet_hours', 'route_modes', 'rules']],
            'examples/reference/garage-sale.community.yaml' => ['garage_sale', 'Community Rummage Sale', '2027-04-10', '2027-04-11', ['categories', 'days', 'early_bird_time', 'route_modes', 'rules']],
        ];
        foreach ($expect as $file => [$type, $name, $from, $to, $settings]) {
            $this->db()->executeStatement('TRUNCATE community_settings, event_occurrences RESTART IDENTITY CASCADE');
            $this->repo()->reset();
            self::assertSame('imported', $bootstrapper->bootstrap(\dirname(__DIR__, 2).'/'.$file)['status'], $file);
            $row = $this->db()->fetchAssociative('SELECT type, name, starts_on, ends_on, settings, state FROM event_occurrences');
            $stored = array_keys(json_decode((string) $row['settings'], true));
            sort($stored);
            self::assertSame([$type, $name, $from, $to, $settings, 'active'], [$row['type'], $row['name'], $row['starts_on'], $row['ends_on'], $stored, $row['state']], $file);
        }
    }

    public function testTheYamlSetsTheFirstEventsSettingsAndRefusesBadOnesBeforeImporting(): void
    {
        $bootstrapper = static::getContainer()->get(CommunityBootstrapper::class);
        $bad = $this->yaml("event:\n  type: garage_sale\n  date: '2027-04-10'\n  ends_on: '2027-04-17'\n  settings:\n    days: ['2027-04-10', '2027-04-18']\n    colour: teal\n    early_bird_time: 7am\n");
        try {
            $bootstrapper->bootstrap($bad);
            self::fail('bad event settings are refused');
        } catch (ConfigException $e) {
            self::assertSame(['event.settings.days: Choose 1 to 3 sale days from 2027-04-10 to 2027-04-17.', 'event.settings.colour: Unknown field.',
                'event.settings.early_bird_time: Use a time like 07:00 for early birds, or leave it empty.'], $e->errors);
        }
        self::assertFalse($this->repo()->isConfigured(), 'nothing was imported');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_occurrences'));

        $file = $this->yaml("event:\n  type: garage_sale\n  name: Community Rummage Sale\n  date: '2027-04-10'\n  ends_on: '2027-04-17'\n  starts_at: '08:00'\n  ends_at: '14:00'\n"
            ."  settings:\n    days: ['2027-04-17', '2027-04-10']\n    categories: [toys, free]\n    early_bird_time: '07:30'\n    rules: ['Park on one side']\n    route_modes: [walking, driving]\n");
        self::assertSame('imported', $bootstrapper->bootstrap($file)['status']);
        $stored = json_decode((string) $this->db()->fetchOne('SELECT settings FROM event_occurrences'), true);
        ksort($stored); // JSONB keeps its own key order
        self::assertSame(['categories' => ['toys', 'free'], 'days' => ['2027-04-10', '2027-04-17'], 'early_bird_time' => '07:30', 'route_modes' => ['walking', 'driving'], 'rules' => ['Park on one side']],
            $stored);
        $this->repo()->reset();
        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame(['garage_sale', 'Community Rummage Sale', ['2027-04-10', '2027-04-17'], ['toys', 'free'], '07:30', ['Park on one side']],
            [$event['type'], $event['name'], $event['days'], $event['categories'], $event['early_bird_time'], $event['rules']]);
        $again = $this->yaml("event:\n  type: garage_sale\n  date: '2027-04-10'\n  ends_on: '2027-04-17'\n  settings:\n    days: ['2027-04-11']\n");
        self::assertSame('imported', $bootstrapper->bootstrap($again, true)['status']);
        self::assertSame(['2027-04-10', '2027-04-17'], json_decode((string) $this->db()->fetchOne('SELECT settings FROM event_occurrences'), true)['days'],
            'a forced re-import never changes an existing event\'s settings (Organizer → Events does)');

        $this->db()->executeStatement('TRUNCATE community_settings, event_occurrences RESTART IDENTITY CASCADE');
        $this->repo()->reset();
        $weekend = $this->yaml("event:\n  type: garage_sale\n  date: '2027-04-10'\n  ends_on: '2027-04-11'\n");
        self::assertSame('imported', $bootstrapper->bootstrap($weekend)['status']);
        self::assertSame(['days' => ['2027-04-10', '2027-04-11']], json_decode((string) $this->db()->fetchOne('SELECT settings FROM event_occurrences'), true), 'a weekend sale takes its days');
        foreach ([$bad, $file, $again, $weekend] as $f) {
            unlink($f);
        }
    }

    private function yaml(string $event): string
    {
        $file = sys_get_temp_dir().'/community-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($file, "community:\n  id: bootville\n  name: Bootville\n  timezone: America/New_York\n".$event);

        return $file;
    }
}
