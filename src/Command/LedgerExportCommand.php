<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AccountLedger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/LedgerExportCommand
 * @description Prints the account ledger as JSON (ids and times only). bin/restore.sh saves it before a
 *     restore and hands it to app:ledger:replay afterwards.
 */
#[AsCommand('app:ledger:export', 'Print the account ledger (deletions and organizer-role changes) as JSON')]
final class LedgerExportCommand extends Command
{
    public function __construct(private readonly AccountLedger $ledger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode(['ledger' => $this->ledger->entries()], \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
