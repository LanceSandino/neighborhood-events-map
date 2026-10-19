<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/DbPingCommand
 * @description Exit 0 when PostgreSQL answers (used by the container entrypoint wait loop).
 */
#[AsCommand('app:db:ping', 'Check database connectivity')]
final class DbPingCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->db->fetchOne('SELECT 1');
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e::class.'</error>');

            return Command::FAILURE;
        }
        $output->writeln('ok');

        return Command::SUCCESS;
    }
}
