<?php

declare(strict_types=1);

namespace App\Command;

use App\Address\AddressNormalizer;
use App\Address\AddressRegistry;
use App\Community\CommunityRepository;
use App\Event\EventOccurrence;
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
 * @description Loads the FICTIONAL demo homes (homes.json next to the bootstrapped demo: examples/demo or
 *     examples/demo-lights). Refuses unless APP_DEMO=1 and the configured community is the demo, so production can
 *     never show invented homes. A home's nights ("all", dates or a range) become its windows and a fictional
 *     "off tonight" may name its night (status_date), so the demo never depends on the day it is seeded. Each home's
 *     listing fields (everything but its address and position) go through ListingDraft and are listed for the
 *     active event. When the demo homes exist, those not listed for the active event (a new one an organizer started
 *     on the demo stack) are listed for it again, from the file, never from an earlier event.
 *
 * @dependencies Address/*, Community/CommunityRepository, Listing/*, Event/EventTypeRegistry, Event/EventOccurrence, Security/UserRepository
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
        $this->addArgument('file', InputArgument::OPTIONAL, 'Demo homes JSON (default: homes.json next to COMMUNITY_BOOTSTRAP_FILE, else examples/demo/homes.json)');
    }

    /** The demo the stack was bootstrapped with brings its own homes (examples/demo-lights/homes.json for the lights demo). */
    private static function defaultFile(): string
    {
        $bootstrap = (string) (getenv('COMMUNITY_BOOTSTRAP_FILE') ?: '');
        $beside = '' === $bootstrap ? '' : \dirname($bootstrap).'/homes.json';

        return '' !== $beside && is_file($beside) ? $beside : 'examples/demo/homes.json';
    }

    /**
     * A demo home's listing input: its fields without the address, its nights ("all", a list of dates, or
     * {from, to}) turned into windows with its hours for a type with several days.
     *
     * @param array<string,mixed> $h
     *
     * @return array<string,mixed>
     */
    private static function listingInput(array $h, EventOccurrence $event): array
    {
        $in = array_diff_key($h, array_flip(['house_number', 'street', 'unit', 'lat', 'lon', 'nights', 'status_date']));
        if (isset($h['nights'])) {
            $all = $event->dates();
            $n = $h['nights'];
            $dates = 'all' === $n ? $all : (\is_array($n) && isset($n['from'], $n['to']) ? array_values(array_filter($all, static fn (string $d) => $d >= $n['from'] && $d <= $n['to'])) : (array) $n);
            $in['windows'] = array_map(static fn ($d) => ['date' => (string) $d, 'start' => (string) ($h['start'] ?? $event->defaultStart), 'end' => (string) ($h['end'] ?? $event->defaultEnd)], $dates);
        }

        return $in;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $c = $this->community->current();
        if (!$this->config->demoMode || !str_ends_with($c->settings['community']['id'], '-demo')) {
            $output->writeln('<error>Refusing: set APP_DEMO=1 and bootstrap a community whose id ends with "-demo".</error>');

            return Command::FAILURE;
        }
        $doc = json_decode((string) file_get_contents((string) ($input->getArgument('file') ?? self::defaultFile())), true, 16, \JSON_THROW_ON_ERROR);
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
            $draft = ListingDraft::fromInput(self::listingInput($h, $event), $c, false, null, $this->types);
            // A fictional "off tonight" names its own night, so the demo looks the same whenever it is seeded.
            $statusDate = ListingDraft::statusDate($draft, $type, $c, $this->clock->now());
            if (null !== $statusDate && \is_string($h['status_date'] ?? null) && $event->contains($h['status_date'])) {
                $statusDate = $h['status_date'];
            }
            $this->listings->insert($this->db, $event, $id, $draft, ListingDraft::windowsFor($draft, $event, $type), $statusDate);
            ++$n;
        }
        $output->writeln($relist && 0 === $n ? 'Demo homes already present; nothing to do.'
            : sprintf($relist ? 'Listed %d FICTIONAL demo homes for the current event.' : 'Seeded %d FICTIONAL demo homes.', $n));

        return Command::SUCCESS;
    }
}
