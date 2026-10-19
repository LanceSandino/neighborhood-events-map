<?php

declare(strict_types=1);

namespace App\Command;

use App\Community\CommunityBootstrapper;
use App\Community\ConfigException;
use App\Geo\GeometryException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/CommunityBootstrapCommand
 * @description Imports community.yaml (+ boundary/zones GeoJSON, logo) on first start. Boundary is
 *     imported unapproved unless --approve-boundary is given by an operator who reviewed it.
 */
#[AsCommand('app:community:bootstrap', 'Import community.yaml and its GeoJSON into the database')]
final class CommunityBootstrapCommand extends Command
{
    public function __construct(private readonly CommunityBootstrapper $bootstrapper)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to community.yaml')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-import even if already configured (creates a new geometry version)')
            ->addOption('approve-boundary', null, InputOption::VALUE_NONE, 'Mark the imported boundary as reviewed and approved');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $r = $this->bootstrapper->bootstrap((string) $input->getArgument('file'), (bool) $input->getOption('force'), (bool) $input->getOption('approve-boundary'));
        } catch (ConfigException|GeometryException $e) {
            foreach ($e->errors as $err) {
                $output->writeln('<error>'.$err.'</error>');
            }

            return Command::FAILURE;
        }
        if ('already-configured' === $r['status']) {
            $output->writeln('Community already configured; nothing imported (use --force to re-import).');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('Imported community "%s".', $r['settings']['community']['name']));
        if (null !== ($r['geometry'] ?? null)) {
            $g = $r['geometry'];
            $output->writeln(sprintf('Geometry version %d: %d boundary feature(s), zones: %s. Boundary %s.', $g['version'], $g['boundary_features'],
                [] === $g['zones'] ? 'none' : implode(', ', $g['zones']), $input->getOption('approve-boundary') ? 'APPROVED' : 'pending organizer approval'));
            foreach ($g['warnings'] as $w) {
                $output->writeln('<comment>Warning: '.$w.'</comment>');
            }
        } else {
            $output->writeln('<comment>No boundary file; draw or import one in the organizer console.</comment>');
        }

        return Command::SUCCESS;
    }
}
