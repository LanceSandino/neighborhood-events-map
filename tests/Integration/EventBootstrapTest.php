<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityBootstrapper;
use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/EventBootstrapTest
 * @description community.yaml creates the install's first event (D-20261031-07): event.type and event.date, or
 *   starts_on/ends_on, become the first, active occurrence; a forced re-import updates that event instead of adding
 *   another; a one-day type keeps one day.
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

    private function yaml(string $event): string
    {
        $file = sys_get_temp_dir().'/community-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($file, "community:\n  id: bootville\n  name: Bootville\n  timezone: America/New_York\n".$event);

        return $file;
    }
}
