<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Claim\ClaimService;
use App\Community\CommunityRepository;
use App\Kernel;
use App\Listing\ListingDraft;
use App\Security\UserRepository;
use App\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Traceability: ACCEPTANCE #8 — concurrent claimants in separate OS processes with separate
 * PostgreSQL connections: exactly one owner and one listing; losers see already_claimed.
 */
#[RequiresPhpExtension('pcntl')]
final class ClaimRaceTest extends AppTestCase
{
    public function testParallelProcessesProduceExactlyOneOwner(): void
    {
        $this->configureCommunity();
        $addressId = $this->addAddress('500', 'Pumpkin Ln', 40.004, -75.004, 'west');
        $users = static::getContainer()->get(UserRepository::class);
        $ids = [];
        for ($i = 0; $i < 8; ++$i) {
            $ids[] = $users->upsertVerified("racer{$i}@example.test")->id;
        }
        $dir = sys_get_temp_dir().'/race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $startAt = microtime(true) + 1.0;
        $target = $this->byId($addressId); // read before the kernel shuts down and the workers fork
        static::ensureKernelShutdown();

        $pids = [];
        foreach ($ids as $i => $userId) {
            $pid = pcntl_fork();
            if (0 === $pid) {
                $status = 'error';
                try {
                    $kernel = new Kernel('test', false);
                    $kernel->boot();
                    $c = $kernel->getContainer()->get('test.service_container');
                    $community = $c->get(CommunityRepository::class)->current();
                    $draft = ListingDraft::fromInput(['candy' => true, 'note' => "racer $i"], $community);
                    while (microtime(true) < $startAt) {
                        usleep(500);
                    }
                    $status = $c->get(ClaimService::class)->claimDirect($target, $draft, $userId, $community)->status;
                } catch (\Throwable $e) {
                    $status = 'error: '.$e->getMessage();
                }
                file_put_contents("$dir/$i", $status);
                // Die without running destructors so inherited parent DB sockets are not closed.
                posix_kill(posix_getpid(), \SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $st);
        }
        $outcomes = array_map(fn ($i) => (string) @file_get_contents("$dir/$i"), array_keys($ids));
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);

        $counts = array_count_values($outcomes);
        self::assertSame(1, $counts['published'] ?? 0, json_encode($outcomes));
        self::assertSame(7, $counts['already_claimed'] ?? 0, json_encode($outcomes));
        $db = $this->db();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM claims WHERE address_id = ?', [$addressId]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM listings WHERE address_id = ?', [$addressId]));
        $winner = (int) $db->fetchOne('SELECT user_id FROM claims WHERE address_id = ?', [$addressId]);
        $note = (string) $db->fetchOne('SELECT note FROM listings WHERE address_id = ?', [$addressId]);
        self::assertSame('racer '.array_search($winner, $ids, true), $note, 'listing belongs to the winning claimant');
    }
}
