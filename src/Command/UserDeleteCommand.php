<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\AccountErasure;
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
 *     pending intents/tokens and their review requests (Admin/AccountErasure); keeps only an anonymous audit
 *     record and the ledger entry that keeps the deletion in force after a restore. Works for someone who only
 *     asked for a review (no account) as well.
 */
#[AsCommand('app:user:delete', 'Delete a user account and its homes/listings on request (privacy)')]
final class UserDeleteCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly EmailNormalizer $emails, private readonly AuditLog $audit, private readonly AccountErasure $erasure)
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
        if (null === $email) {
            $output->writeln('<error>Invalid email address.</error>');

            return Command::FAILURE;
        }
        $id = $this->db->fetchOne('SELECT id FROM users WHERE email_normalized = ?', [$email]);
        $homes = false === $id ? 0 : (int) $this->db->fetchOne('SELECT COUNT(*) FROM claims WHERE user_id = ?', [$id]);
        // A review request needs no account, so its reporter may have no users row: their data is erased too.
        $reviews = (int) $this->db->fetchOne('SELECT COUNT(*) FROM disputes WHERE reporter_email = ?', [$email]);
        $pending = (int) $this->db->fetchOne('SELECT (SELECT COUNT(*) FROM auth_link_tokens WHERE email_normalized = :e) + (SELECT COUNT(*) FROM claim_intents WHERE email_normalized = :e)', ['e' => $email]);
        if (false === $id && 0 === $reviews + $pending) {
            $output->writeln('<error>No such user, review request or pending link for that address.</error>');

            return Command::FAILURE;
        }
        $who = false === $id ? 'the data stored for that address (no account)' : sprintf('user #%d', $id);
        if (!$input->getOption('confirm')) {
            $output->writeln(sprintf('Would delete %s, release %d home(s) and erase %d review request(s) and %d pending link(s). Re-run with --confirm.', $who, $homes, $reviews, $pending));

            return Command::SUCCESS;
        }
        $this->erasure->erase(false === $id ? null : (int) $id, $email);
        $this->audit->record(null, 'cli', 'user.deleted', 'user', false === $id ? 'none' : (string) $id, ['homes_released' => $homes, 'review_requests' => $reviews], 'cli');
        $output->writeln(sprintf('Deleted %s, released %d home(s) and erased %d review request(s).', $who, $homes, $reviews));

        return Command::SUCCESS;
    }
}
