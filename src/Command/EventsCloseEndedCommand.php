<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\EndedEvents;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/EventsCloseEndedCommand
 * @description Closes open events whose last day has passed in the community's time zone (Event/EndedEvents,
 *     D-20261104-01). Runs once at app start, after the migrations (docker/php/entrypoint.sh; a failure never stops the
 *     app), and in the maintenance loop. EVENTS_AUTO_CLOSE turns it off for fixed-date demo communities: on by default in
 *     compose.yaml, off in compose.dev.yaml and the test environment; --force runs it anyway. Exits non-zero only when an
 *     event could not be closed.
 *
 * @dependencies Event/EndedEvents
 */
#[AsCommand('app:events:close-ended', 'Close open events whose last day has passed in the community time zone')]
final class EventsCloseEndedCommand extends Command
{
    public function __construct(private readonly EndedEvents $ended, private readonly bool $enabled = true)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run even when EVENTS_AUTO_CLOSE=0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->enabled && true !== $input->getOption('force')) {
            $output->writeln('Automatic closing is off (EVENTS_AUTO_CLOSE=0): nothing changed.');

            return Command::SUCCESS;
        }
        $run = $this->ended->run();
        foreach ($run['closed'] as $c) {
            $output->writeln(sprintf('Closed “%s” (its last day was %s); next year\'s event #%d is hidden.', $c['name'], $c['last_day'], $c['next_id']));
        }
        foreach ($run['skipped'] as $s) {
            $output->writeln(sprintf('Skipped “%s” (%s).', $s['name'], $s['reason']));
        }
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        foreach ($run['failed'] as $f) {
            $errors->writeln(sprintf('Could not close “%s”: %s', $f['name'], $f['error']));
        }
        if ([] === $run['closed'] && [] === $run['skipped'] && [] === $run['failed']) {
            $output->writeln('No event has ended: nothing changed.');
        }

        return [] === $run['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
