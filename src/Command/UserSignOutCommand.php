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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/UserSignOutCommand
 * @description Ends every session of one account (e.g. a resident or organizer who lost a phone), the same as
 *     the resident's own "Sign out everywhere" button.
 */
#[AsCommand('app:user:sign-out', 'Sign an account out on every device')]
final class UserSignOutCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly EmailNormalizer $emails, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $this->emails->normalize((string) $input->getArgument('email'));
        $user = null === $email ? null : $this->users->findByEmail($email);
        if (null === $user) {
            $output->writeln('<error>No account with that email address.</error>');

            return Command::FAILURE;
        }
        $this->users->endSessions($user->id);
        $this->audit->record(null, 'cli', 'auth.signed_out_everywhere', 'user', (string) $user->id, [], 'cli');
        $output->writeln(sprintf('Signed user #%d out on every device. They can sign in again with an email link.', $user->id));

        return Command::SUCCESS;
    }
}
