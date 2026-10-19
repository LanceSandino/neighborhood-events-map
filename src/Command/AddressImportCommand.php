<?php

declare(strict_types=1);

namespace App\Command;

use App\Address\AddressCsvImporter;
use App\Community\CommunityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/AddressImportCommand
 * @description CLI front-end for the reviewed address inventory CSV import.
 */
#[AsCommand('app:addresses:import', 'Import a reviewed address CSV (address, latitude, longitude, zone_key)')]
final class AddressImportCommand extends Command
{
    public function __construct(private readonly AddressCsvImporter $importer, private readonly CommunityRepository $community)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED)
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update coordinates/zone of existing addresses')
            ->addOption('dry-run', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('file');
        if (!is_readable($path)) {
            $output->writeln('<error>Cannot read '.$path.'</error>');

            return Command::FAILURE;
        }
        $r = $this->importer->import((string) file_get_contents($path), $this->community->current(), (bool) $input->getOption('update'), (bool) $input->getOption('dry-run'));
        $output->writeln(sprintf('%simported %d, updated %d, unchanged %d, skipped %d', $input->getOption('dry-run') ? '[dry run] ' : '', $r['imported'], $r['updated'], $r['unchanged'], \count($r['skipped'])));
        foreach ($r['skipped'] as $s) {
            $output->writeln(sprintf('  line %d: %s', $s['line'], $s['reason']));
        }

        return Command::SUCCESS;
    }
}
