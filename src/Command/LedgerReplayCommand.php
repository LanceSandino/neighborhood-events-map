<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\LedgerReplay;
use App\Audit\AccountLedger;
use App\Audit\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/LedgerReplayCommand
 * @description After a restore: merges a ledger saved by app:ledger:export (--merge=FILE, or - for stdin), then
 *     re-applies every entry, so accounts deleted and organizers changed after the backup stay that way.
 */
#[AsCommand('app:ledger:replay', 'Re-apply deletions and organizer-role changes to a restored database')]
final class LedgerReplayCommand extends Command
{
    public function __construct(private readonly AccountLedger $ledger, private readonly LedgerReplay $replay, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('merge', null, InputOption::VALUE_REQUIRED, 'Ledger JSON from app:ledger:export to merge first (- reads stdin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $merged = 0;
        $from = $input->getOption('merge');
        if (\is_string($from) && '' !== $from) {
            $json = '-' === $from ? stream_get_contents(\STDIN) : @file_get_contents($from);
            if (false === $json || '' === trim($json)) {
                $output->writeln('<comment>No saved ledger to merge; re-applying the restored database\'s own ledger.</comment>');
            } else {
                try {
                    $merged = $this->ledger->merge(json_decode($json, true, 8, \JSON_THROW_ON_ERROR)['ledger'] ?? null);
                } catch (\JsonException|\InvalidArgumentException $e) {
                    $output->writeln('<error>The saved ledger is not usable: '.$e->getMessage().'</error>');

                    return Command::FAILURE;
                }
            }
        }
        $done = $this->replay->replay();
        $this->audit->record(null, 'cli', 'ledger.replayed', 'ledger', null, ['merged' => $merged] + $done, 'cli');
        $output->writeln(sprintf('Ledger re-applied: %d entr%s merged, %d account(s) and %d review request(s) erased again, %d organizer role(s) corrected.',
            $merged, 1 === $merged ? 'y' : 'ies', $done['users'], $done['reviews'], $done['roles']));

        return Command::SUCCESS;
    }
}
