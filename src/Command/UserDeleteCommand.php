<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Security\EmailNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/UserDeleteCommand
 * @description Data-deletion request handling: removes a resident's account, their claims and listings,
 *     pending intents/tokens and their review requests; keeps only an anonymous audit record.
 */
#[AsCommand('app:user:delete', 'Delete a user account and its homes/listings on request (privacy)')]
final class UserDeleteCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly EmailNormalizer $emails, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required: perform the deletion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $this->emails->normalize((string) $input->getArgument('email'));
        $id = null === $email ? false : $this->db->fetchOne('SELECT id FROM users WHERE email_normalized = ?', [$email]);
        if (false === $id) {
            $output->writeln('<error>No such user.</error>');

            return Command::FAILURE;
        }
        $homes = (int) $this->db->fetchOne('SELECT COUNT(*) FROM claims WHERE user_id = ?', [$id]);
        if (!$input->getOption('confirm')) {
            $output->writeln(sprintf('Would delete user #%d and release %d home(s). Re-run with --confirm.', $id, $homes));

            return Command::SUCCESS;
        }
        $this->db->transactional(function (Connection $db) use ($id, $email): void {
            $db->executeStatement('DELETE FROM claims WHERE user_id = ?', [$id]); // listings cascade
            $db->executeStatement('DELETE FROM auth_link_tokens WHERE email_normalized = ?', [$email]);
            $db->executeStatement('DELETE FROM claim_intents WHERE email_normalized = ?', [$email]);
            $db->executeStatement('DELETE FROM disputes WHERE reporter_email = ?', [$email]);
            $db->executeStatement('UPDATE disputes SET resolved_by = NULL WHERE resolved_by = ?', [$id]);
            $db->executeStatement('DELETE FROM users WHERE id = ?', [$id]);
        });
        $this->audit->record(null, 'cli', 'user.deleted', 'user', (string) $id, ['homes_released' => $homes], 'cli');
        $output->writeln(sprintf('Deleted user #%d and released %d home(s).', $id, $homes));

        return Command::SUCCESS;
    }
}
