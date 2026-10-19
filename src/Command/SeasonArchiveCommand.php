<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Listing\ListingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/SeasonArchiveCommand
 * @description After the event: unpublish listings from earlier event dates so homes must opt in
 *     again next season. (Public queries already show only the configured event date.)
 */
#[AsCommand('app:season:archive', 'Unpublish listings from past event dates')]
final class SeasonArchiveCommand extends Command
{
    public function __construct(private readonly ListingRepository $listings, private readonly CommunityRepository $community, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $n = $this->listings->archiveBefore($this->community->current()->eventDate());
        $this->audit->record(null, 'cli', 'season.archived', 'listings', null, ['count' => $n], 'cli');
        $output->writeln(sprintf('Unpublished %d listing(s) from earlier seasons.', $n));

        return Command::SUCCESS;
    }
}
