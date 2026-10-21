<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Community\ConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/ZoneLabelCommand
 * @description Pins a zone's name to a point on the public map (or --reset lets the map choose again). The same
 *     setting organizers set by dragging the name on the Boundary & zones page; validated by CommunitySchema.
 * @dependencies CommunityRepository, AuditLog
 */
#[AsCommand('app:zone:label', 'Pin a zone\'s name to a map point (latitude longitude), or --reset it')]
final class ZoneLabelCommand extends Command
{
    public function __construct(private readonly CommunityRepository $community, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('zone', InputArgument::REQUIRED, 'Zone key')
            ->addArgument('lat', InputArgument::OPTIONAL, 'Latitude')
            ->addArgument('lon', InputArgument::OPTIONAL, 'Longitude')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Let the map choose the spot again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->community->settings();
        $key = (string) $input->getArgument('zone');
        $i = null === $settings ? false : array_search($key, array_column($settings['zones'] ?? [], 'key'), true);
        if (null === $settings || false === $i) {
            $output->writeln(sprintf('<error>No zone "%s".</error>', $key));

            return Command::FAILURE;
        }
        $lat = $input->getArgument('lat');
        $lon = $input->getArgument('lon');
        if ($input->getOption('reset')) {
            $point = null;
        } elseif (is_numeric($lat) && is_numeric($lon)) {
            $point = [(float) $lon, (float) $lat];
        } else {
            $output->writeln('<error>Give a latitude and longitude, or --reset.</error>');

            return Command::FAILURE;
        }
        $settings['zones'][$i]['label_at'] = $point;
        try {
            $saved = $this->community->saveSettings($settings, null);
        } catch (ConfigException $e) {
            $output->writeln('<error>'.implode(' ', $e->errors).'</error>');

            return Command::FAILURE;
        }
        $this->audit->record(null, 'cli', 'community.zone_label_moved', 'zone', $key, ['label_at' => $point], 'cli');
        $at = $saved['zones'][$i]['label_at'];
        $output->writeln(null === $at
            ? sprintf('The map chooses where the name of "%s" goes.', $key)
            : sprintf('The name of "%s" sits at %s, %s (latitude, longitude).', $key, $at[1], $at[0]));

        return Command::SUCCESS;
    }
}
