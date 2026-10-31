<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Event\EventConflictException;
use App\Event\EventLifecycle;
use App\Kernel;
use App\Tests\Support\AppTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * @module tests/Integration/EventLifecycleRaceTest
 * @description The event invariants under concurrency (ADR 0011, D-20261031-37; this replaces the one-active-event race
 *   test of 0.11, whose switch no longer exists): organizers in separate OS processes with separate PostgreSQL
 *   connections act at the same instant — opening different events of one type, closing the same event, making
 *   different events the default. Exactly one of each conflicting change wins, the others are refused, closing creates
 *   next year's event exactly once, there is always exactly one default, and the partial unique indexes and the check
 *   refuse a second current event of a type, a second default and a default that is not public even without the app.
 * @dependencies tests/Support/AppTestCase, Event/EventLifecycle
 */
#[RequiresPhpExtension('pcntl')]
final class EventLifecycleRaceTest extends AppTestCase
{
    public function testConcurrentOpensOfEventsOfOneTypeHaveExactlyOneWinner(): void
    {
        $this->configureCommunity();
        $this->db()->executeStatement("UPDATE event_occurrences SET state = 'archived', archived_at = NOW(), is_default = FALSE");
        $drafts = [];
        foreach (['2027-10-29', '2027-10-30', '2027-10-31', '2027-11-01'] as $day) {
            $drafts[] = $this->draft($day);
        }
        $outcomes = $this->race(array_map(static fn (int $d) => ['open', $d], $drafts));
        $counts = array_count_values($outcomes);
        self::assertSame(1, $counts['ok'] ?? 0, json_encode($outcomes));
        self::assertSame(3, $counts['type_current'] ?? 0, json_encode($outcomes));
        $states = $this->db()->fetchAllKeyValue('SELECT id, state FROM event_occurrences ORDER BY id');
        self::assertSame(1, \count(array_keys($states, 'open', true)));
        $winner = $drafts[array_search('ok', $outcomes, true)];
        self::assertSame('open', $states[$winner], 'the winner is the one that is open');
        self::assertSame(3, \count(array_keys($states, 'draft', true)), 'the losers stay drafts');
        self::assertSame([$winner], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')), 'and it became the default');
    }

    public function testTheSameEventClosedThreeTimesAtOnceIsClosedOnceWithOneNextYear(): void
    {
        $this->configureCommunity();
        $open = (int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE state = 'open'");
        $outcomes = $this->race([['close', $open], ['close', $open], ['close', $open]]);
        $counts = array_count_values($outcomes);
        ksort($counts);
        self::assertSame(['not_open' => 2, 'ok' => 1], $counts, json_encode($outcomes));
        self::assertSame('closed', $this->db()->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$open]));
        self::assertSame([['type' => 'halloween', 'starts_on' => '2027-10-31', 'state' => 'upcoming', 'dates_tentative' => true]],
            $this->db()->fetchAllAssociative('SELECT type, starts_on, state, dates_tentative FROM event_occurrences WHERE id <> ?', [$open]), 'next year\'s event exactly once');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.closed'"));
    }

    public function testConcurrentDefaultChangesLeaveExactlyOneDefault(): void
    {
        $this->configureCommunity();
        $events = [(int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE state = 'open'")];
        foreach ([['holiday_lights', '2026-12-01', '2027-01-03', 'open'], ['garage_sale', null, null, 'upcoming']] as [$type, $from, $to, $state]) {
            $events[] = (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
                VALUES (?, 'Next', ?, ?, '17:00', '21:00', ?, NOW()) RETURNING id", [$type, $from, $to, $state]);
        }
        $outcomes = $this->race(array_map(static fn (int $id) => ['makeDefault', $id], [...$events, ...$events]));
        self::assertSame(['ok' => 6], array_count_values($outcomes), json_encode($outcomes));
        $defaults = array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default'));
        self::assertCount(1, $defaults, 'exactly one default, whoever came last');
        self::assertContains($defaults[0], $events);
        self::assertSame(6, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.default'"));
    }

    public function testTheDatabaseRefusesASecondCurrentEventOfAType(): void
    {
        $this->configureCommunity();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->db()->executeStatement("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('halloween', 'Second', '2027-10-31', '2027-10-31', '17:00', '21:00', 'upcoming', NOW())");
    }

    public function testTheDatabaseRefusesASecondDefaultAndADefaultThatIsNotPublic(): void
    {
        $this->configureCommunity();
        $lights = (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('holiday_lights', 'Lights', '2026-12-01', '2027-01-03', '18:00', '22:00', 'open', NOW()) RETURNING id");
        try {
            $this->db()->executeStatement('UPDATE event_occurrences SET is_default = TRUE WHERE id = ?', [$lights]);
            self::fail('a second default');
        } catch (UniqueConstraintViolationException) {
        }
        $draft = $this->draft('2027-10-31');
        $this->db()->executeStatement('UPDATE event_occurrences SET is_default = FALSE');
        $this->expectException(DriverException::class); // event_occurrences_default_public
        $this->db()->executeStatement('UPDATE event_occurrences SET is_default = TRUE WHERE id = ?', [$draft]);
    }

    private function draft(string $day): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('halloween', 'Halloween', ?, ?, '17:00', '21:00', 'draft', NOW()) RETURNING id", [$day, $day]);
    }

    /**
     * Runs one EventLifecycle step per [method, event id] pair in its own forked process, all starting at the same
     * instant.
     *
     * @param list<array{0:string,1:int}> $calls
     *
     * @return list<string> each process's outcome: ok, or the conflict reason
     */
    private function race(array $calls): array
    {
        $admin = (int) $this->db()->fetchOne("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('racer@example.test', TRUE, NOW()) RETURNING id");
        $dir = sys_get_temp_dir().'/event-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $startAt = microtime(true) + 1.0;
        static::ensureKernelShutdown();
        $pids = [];
        foreach ($calls as $i => [$method, $id]) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                $status = 'error';
                try {
                    $kernel = new Kernel('test', false);
                    $kernel->boot();
                    $lifecycle = $kernel->getContainer()->get('test.service_container')->get(EventLifecycle::class);
                    while (microtime(true) < $startAt) {
                        usleep(500);
                    }
                    'close' === $method ? $lifecycle->close($id, null, $admin) : $lifecycle->{$method}($id, $admin);
                    $status = 'ok';
                } catch (EventConflictException $e) {
                    $status = $e->reason;
                } catch (\Throwable $e) {
                    $status = 'error: '.$e->getMessage();
                }
                file_put_contents("$dir/$i", $status);
                posix_kill(posix_getpid(), \SIGKILL); // no destructors: the parent's DB sockets stay open
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $st);
        }
        $outcomes = array_map(static fn (int $i) => (string) @file_get_contents("$dir/$i"), array_keys($calls));
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);

        return $outcomes;
    }
}
