<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Event\EventConflictException;
use App\Event\EventLifecycle;
use App\Kernel;
use App\Tests\Support\AppTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * @module tests/Integration/EventActivationRaceTest
 * @description One active event under concurrency (D-20261031-05): organizers in separate OS processes with separate
 *   PostgreSQL connections switch the map to different drafts (or the same one) at the same instant; exactly one
 *   wins, the others are refused, and the partial unique index refuses a second active row even without the app.
 * @dependencies tests/Support/AppTestCase, Event/EventLifecycle
 */
#[RequiresPhpExtension('pcntl')]
final class EventActivationRaceTest extends AppTestCase
{
    public function testConcurrentSwitchesToDifferentDraftsHaveExactlyOneWinner(): void
    {
        $this->configureCommunity();
        $current = (int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE state = 'active'");
        $drafts = [];
        foreach (['2027-10-29', '2027-10-30', '2027-10-31', '2027-11-01'] as $day) {
            $drafts[] = $this->draft($day);
        }
        $outcomes = $this->race(array_map(static fn (int $d) => [$d, $current], $drafts));
        $counts = array_count_values($outcomes);
        self::assertSame(1, $counts['activated'] ?? 0, json_encode($outcomes));
        self::assertSame(3, $counts['changed'] ?? 0, json_encode($outcomes));
        $states = $this->db()->fetchAllKeyValue('SELECT id, state FROM event_occurrences ORDER BY id');
        self::assertSame(1, \count(array_keys($states, 'active', true)));
        self::assertSame('archived', $states[$current]);
        $winner = $drafts[array_search('activated', $outcomes, true)];
        self::assertSame('active', $states[$winner], 'the winner is the one that is active');
        self::assertSame(3, \count(array_keys($states, 'draft', true)), 'the losers stay drafts');
    }

    public function testTheSameDraftActivatedTwiceAtOnceIsActivatedOnce(): void
    {
        $this->configureCommunity();
        $current = (int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE state = 'active'");
        $draft = $this->draft('2027-10-31');
        $outcomes = $this->race([[$draft, $current], [$draft, $current], [$draft, $current]]);
        self::assertSame(['activated' => 1], array_intersect_key(array_count_values($outcomes), ['activated' => 1]), json_encode($outcomes));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM event_occurrences WHERE state = 'active'"));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.activated'"));
    }

    public function testTheDatabaseRefusesASecondActiveEvent(): void
    {
        $this->configureCommunity();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->db()->executeStatement("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('halloween', 'Second', '2027-10-31', '2027-10-31', '17:00', '21:00', 'active', NOW())");
    }

    private function draft(string $day): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('halloween', 'Halloween', ?, ?, '17:00', '21:00', 'draft', NOW()) RETURNING id", [$day, $day]);
    }

    /**
     * Runs EventLifecycle::activate in one forked process per [draft, replaces] pair, all starting at the same instant.
     *
     * @param list<array{0:int,1:int}> $calls
     *
     * @return list<string> each process's outcome: activated, or the conflict reason
     */
    private function race(array $calls): array
    {
        $admin = (int) $this->db()->fetchOne("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('racer@example.test', TRUE, NOW()) RETURNING id");
        $dir = sys_get_temp_dir().'/event-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $startAt = microtime(true) + 1.0;
        static::ensureKernelShutdown();
        $pids = [];
        foreach ($calls as $i => [$draft, $replaces]) {
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
                    $lifecycle->activate($draft, $replaces, $admin);
                    $status = 'activated';
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
