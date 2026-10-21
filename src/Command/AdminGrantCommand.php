<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Security\EmailNormalizer;
use App\Security\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/AdminGrantCommand
 * @description Out-of-band organizer role management (the web app can never self-assign it). A revoke
 *     fails loudly while ORGANIZER_EMAILS still lists the address, which keeps the role.
 */
#[AsCommand('app:admin:grant', 'Grant (or --revoke) the organizer role for an email address')]
final class AdminGrantCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly EmailNormalizer $emails, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addOption('revoke', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $this->emails->normalize((string) $input->getArgument('email'));
        if (null === $email) {
            $output->writeln('<error>Invalid email address.</error>');

            return Command::FAILURE;
        }
        $grant = !$input->getOption('revoke');
        $user = $this->users->setAdmin($email, $grant);
        $this->audit->record(null, 'cli', $grant ? 'admin.granted' : 'admin.revoked', 'user', (string) $user->id, [], 'cli');
        if (!$grant && $user->isAdmin) {
            $output->writeln(sprintf('<error>Removed the database grant for %s (user #%d), but it is still an organizer because ORGANIZER_EMAILS lists it. '
                .'Remove it from ORGANIZER_EMAILS in .env and recreate the app container to revoke the role.</error>', $email, $user->id));

            return Command::FAILURE;
        }
        $output->writeln(sprintf('%s organizer role for %s (user #%d). They sign in with an email link at /login.', $grant ? 'Granted' : 'Revoked', $email, $user->id));

        return Command::SUCCESS;
    }
}
