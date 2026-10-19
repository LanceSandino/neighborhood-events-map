<?php

declare(strict_types=1);

namespace App\Command;

use App\Claim\ClaimService;
use App\Security\MagicLinkTokens;
use App\Security\ProofOfWork;
use App\Security\Throttle;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/MaintenancePurgeCommand
 * @description Deletes expired sign-in tokens, PoW challenges, claim intents, old throttle events,
 *     expired sessions and stale route-cache rows. Runs hourly in the `maintenance` service.
 */
#[AsCommand('app:maintenance:purge', 'Delete expired tokens, PoW challenges, intents, throttle events, sessions and route cache')]
final class MaintenancePurgeCommand extends Command
{
    public function __construct(
        private readonly MagicLinkTokens $tokens,
        private readonly ProofOfWork $pow,
        private readonly Throttle $throttle,
        private readonly ClaimService $claims,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Session rows carry their expiry as a Unix time written with the real clock (PdoSessionHandler).
        $sessions = $this->db->executeStatement('DELETE FROM sessions WHERE sess_lifetime < ?', [time()]);
        $routes = $this->db->executeStatement('DELETE FROM route_cache WHERE expires_at < ?', [$this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.uP')]);
        $output->writeln(sprintf('Purged: %d tokens, %d PoW challenges, %d claim intents, %d throttle events, %d expired sessions, %d route-cache entries.',
            $this->tokens->purge(), $this->pow->purge(), $this->claims->purgeExpiredIntents(), $this->throttle->purge(), $sessions, $routes));

        return Command::SUCCESS;
    }
}
