<?php

declare(strict_types=1);

namespace App\Command;

use App\Address\AddressNormalizer;
use App\Address\AddressRegistry;
use App\Community\CommunityRepository;
use App\Event\EventTypeRegistry;
use App\Listing\ListingDraft;
use App\Listing\ListingRepository;
use App\Shared\RuntimeConfig;
use App\Security\UserRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @module Command/DemoSeedCommand
 * @description Loads the FICTIONAL demo homes (examples/demo/homes.json). Refuses unless APP_DEMO=1
 *     and the configured community is the demo, so production can never show invented homes. Each home's
 *     listing fields (everything but its address and position) go through ListingDraft and are listed for the
 *     active event. When the demo homes exist, those not listed for the active event (a new one an organizer started
 *     on the demo stack) are listed for it again, from the file, never from an earlier event.
 *
 * @dependencies Address/*, Community/CommunityRepository, Listing/*, Event/EventTypeRegistry, Security/UserRepository
 */
#[AsCommand('app:demo:seed', 'Load fictional demo homes (APP_DEMO=1 only)')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly CommunityRepository $community,
        private readonly AddressRegistry $addresses,
        private readonly AddressNormalizer $normalizer,
        private readonly UserRepository $users,
        private readonly ListingRepository $listings,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly EventTypeRegistry $types,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Demo homes JSON', 'examples/demo/homes.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->community->current();
        if (!$this->config->demoMode || !str_ends_with($c->settings['community']['id'], '-demo')) {
            $output->writeln('<error>Refusing: set APP_DEMO=1 and bootstrap a community whose id ends with "-demo".</error>');

            return Command::FAILURE;
        }
        $doc = json_decode((string) file_get_contents((string) $input->getArgument('file')), true, 16, \JSON_THROW_ON_ERROR);
        if (true !== ($doc['fictional'] ?? null)) {
            $output->writeln('<error>Demo file must declare "fictional": true.</error>');

            return Command::FAILURE;
        }
        $event = $c->event;
        if (null === $event) {
            $output->writeln('<error>No event is active; start one under Organizer → Events first.</error>');

            return Command::FAILURE;
        }
        $relist = (int) $this->db->fetchOne('SELECT COUNT(*) FROM claims') > 0;
        $type = $this->types->for($c);
        $n = 0;
        foreach ($doc['homes'] as $i => $h) {
            $parts = $this->normalizer->fromParts($h['house_number'], $h['street']);
            if ($relist) {
                $id = (int) ($this->addresses->findByNormalized($parts['normalized'])['id'] ?? 0);
                if (0 === $id || false === $this->db->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$id])
                    || false !== $this->db->fetchOne('SELECT 1 FROM event_listings WHERE event_id = ? AND address_id = ?', [$event->id, $id])) {
                    continue; // given up since, or already listed for this event: left as it is
                }
            } else {
                $id = $this->addresses->insert($parts, null, (float) $h['lat'], (float) $h['lon'], 'admin', $c->insideBoundary((float) $h['lat'], (float) $h['lon']), true);
                $user = $this->users->upsertVerified(sprintf('demo-resident-%02d@example.invalid', $i + 1));
                $this->db->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
                    [$id, $user->id, $this->clock->now()->format('Y-m-d H:i:s.uP'), $this->clock->now()->format('Y-m-d H:i:s.uP')]);
            }
            $draft = ListingDraft::fromInput(array_diff_key($h, array_flip(['house_number', 'street', 'unit', 'lat', 'lon'])), $c, false, null, $this->types);
            $this->listings->insert($this->db, $event, $id, $draft, ListingDraft::windowsFor($draft, $event, $type), ListingDraft::statusDate($draft, $type, $c, $this->clock->now()));
            ++$n;
        }
        $output->writeln($relist && 0 === $n ? 'Demo homes already present; nothing to do.'
            : sprintf($relist ? 'Listed %d FICTIONAL demo homes for the current event.' : 'Seeded %d FICTIONAL demo homes.', $n));

        return Command::SUCCESS;
    }
}
