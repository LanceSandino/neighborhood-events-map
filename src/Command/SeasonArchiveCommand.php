<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Listing\LegacyListingBridge;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/SeasonArchiveCommand
 * @description After the event: unpublish legacy listing rows from days before the active event, so the previous
 *     release (after an image-only rollback) cannot show them either. Event-scoped listings need no archive run:
 *     only the active event's listings are ever public, and archiving an event (Organizer → Events) hides its homes.
 *
 * @dependencies Community/CommunityRepository, Listing/LegacyListingBridge, Audit/AuditLog, DBAL
 */
#[AsCommand('app:season:archive', 'Unpublish listings from past event dates')]
final class SeasonArchiveCommand extends Command
{
    public function __construct(
        private readonly LegacyListingBridge $bridge,
        private readonly CommunityRepository $community,
        private readonly AuditLog $audit,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $event = $this->community->current()->event;
        if (null === $event) {
            $output->writeln('No event is active: there is no current season to compare with. Nothing changed.');

            return Command::SUCCESS;
        }
        $n = $this->db->transactional(fn (Connection $db) => $this->bridge->unpublishBefore($db, $event->startsOn));
        $this->audit->record(null, 'cli', 'season.archived', 'listings', null, ['count' => $n, 'before' => $event->startsOn], 'cli');
        $output->writeln(sprintf('Unpublished %d listing(s) from earlier seasons.', $n));

        return Command::SUCCESS;
    }
}
