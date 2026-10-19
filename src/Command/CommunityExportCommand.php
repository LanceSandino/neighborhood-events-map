<?php

declare(strict_types=1);

namespace App\Command;

use App\Community\CommunityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * @module Command/CommunityExportCommand
 * @description Exports DB settings to community.yaml and active geometry to geometry.geojson so a
 *     redeploy or backup can be reproduced exactly.
 */
#[AsCommand('app:community:export', 'Export settings (YAML) and boundary/zones (GeoJSON)')]
final class CommunityExportCommand extends Command
{
    public function __construct(private readonly CommunityRepository $repo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Output directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->repo->settings();
        if (null === $settings) {
            $output->writeln('<error>Community is not configured.</error>');

            return Command::FAILURE;
        }
        $dir = rtrim((string) $input->getArgument('directory'), '/');
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            $output->writeln('<error>Cannot create '.$dir.'</error>');

            return Command::FAILURE;
        }
        $settings['map']['boundary_file'] = 'geometry.geojson';
        unset($settings['branding']['logo']);
        file_put_contents($dir.'/community.yaml', "# Exported community settings\n".Yaml::dump($settings, 6, 2));
        file_put_contents($dir.'/geometry.geojson', json_encode($this->repo->exportGeometry(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)."\n");
        $output->writeln('Wrote '.$dir.'/community.yaml and '.$dir.'/geometry.geojson');

        return Command::SUCCESS;
    }
}
