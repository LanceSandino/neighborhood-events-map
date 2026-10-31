<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Address\AddressNormalizer;
use App\Address\AddressRegistry;
use App\Address\InvalidAddressException;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use App\Event\EventTypeRegistry;
use App\Listing\ListingRepository;
use App\Security\ClientIdentity;
use App\Security\ProofOfWork;
use App\Security\Throttle;
use App\Shared\ApiError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @module Controller/Api/PublicApiController
 * @description Anonymous, read-mostly family endpoints: community metadata, published homes,
 *     narrow address lookup / claim status for the listing form, and PoW challenges.
 *     Never returns emails, owner ids or the unclaimed address inventory. Homes are projected by the
 *     community's event type.
 *
 * @dependencies Community/*, Listing/ListingRepository, Event/EventTypeRegistry, Address/*, Security/*
 */
final class PublicApiController
{
    public function __construct(
        private readonly CommunityRepository $community,
        private readonly CommunityPresenter $presenter,
        private readonly ListingRepository $listings,
        private readonly AddressRegistry $addresses,
        private readonly AddressNormalizer $normalizer,
        private readonly ProofOfWork $pow,
        private readonly Throttle $throttle,
        private readonly ClientIdentity $client,
        private readonly EventTypeRegistry $types,
    ) {
    }

    #[Route('/api/community', name: 'api_community', methods: ['GET'])]
    #[Route('/api/event', name: 'api_event', methods: ['GET'])]
    public function community(): JsonResponse
    {
        $c = $this->community->current();
        $homes = $this->listings->publicHomes($c->eventDate(), $c->hiddenZoneKeys(), $this->types->for($c));

        return self::fresh(new JsonResponse($this->presenter->publicPayload($c, $homes)));
    }

    #[Route('/api/homes', name: 'api_homes', methods: ['GET'])]
    public function homes(): JsonResponse
    {
        $c = $this->community->current();

        return self::fresh(new JsonResponse([
            'event_date' => $c->eventDate(),
            'timezone' => $c->settings['community']['timezone'],
            'homes' => $this->listings->publicHomes($c->eventDate(), $c->hiddenZoneKeys(), $this->types->for($c)),
        ]));
    }

    #[Route('/api/homes/{id}', name: 'api_home', requirements: ['id' => '\d{1,12}'], methods: ['GET'])]
    public function home(int $id): JsonResponse
    {
        $c = $this->community->current();
        $home = $this->listings->publicHome($id, $c->eventDate(), $c->hiddenZoneKeys(), $this->types->for($c));

        return null === $home ? ApiError::response(404, 'not_found', 'That home is not listed.') : self::fresh(new JsonResponse(['home' => $home]));
    }

    #[Route('/api/addresses/lookup', name: 'api_address_lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        $this->throttle->hit('lookup_ip', $this->client->ip($request));
        $c = $this->community->current();
        if (!$c->registrationState()['open']) {
            // The registry is only needed while homes can be listed; closed means nothing to probe.
            return new JsonResponse(['results' => []], 200, ['Cache-Control' => 'no-store']);
        }
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        if (!AddressNormalizer::isPlain($q)) {
            return new JsonResponse(['results' => []], 200, ['Cache-Control' => 'no-store']); // no address has special characters
        }
        $prefixes = [strtoupper(preg_replace('~\s+~', ' ', preg_replace('~[^A-Za-z0-9 -]~', ' ', $q) ?? '') ?? '')];
        try {
            $prefixes[] = $this->normalizer->parse($q)['normalized'];
        } catch (InvalidAddressException) {
        }
        $results = [];
        foreach (array_unique(array_map('trim', $prefixes)) as $prefix) {
            foreach ($this->addresses->lookup($prefix) as $r) {
                $zone = $c->zone($r['zone_key']);
                if (null !== $zone && 'hidden' === $zone['status']) {
                    continue; // a hidden zone is not public, not even through the lookup
                }
                $results[$r['id']] = $r + ['zone_label' => null === $zone ? null : $zone['label'].('coming_soon' === $zone['status'] ? ' · coming soon' : '')];
            }
        }

        return new JsonResponse(['results' => array_values(\array_slice($results, 0, 8, true))], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/pow', name: 'api_pow', methods: ['GET'])]
    public function pow(Request $request): JsonResponse
    {
        $purpose = (string) $request->query->get('purpose', '');
        if (!\in_array($purpose, ProofOfWork::PURPOSES, true)) {
            return ApiError::response(400, 'bad_purpose', 'purpose must be login, claim or dispute.');
        }
        $this->throttle->hit('pow_ip', $this->client->ip($request));

        return new JsonResponse(['challenge' => $this->pow->issue($purpose, $this->client->ipHash($request))], 200, ['Cache-Control' => 'no-store']);
    }

    private static function fresh(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Cache-Control', 'no-cache, max-age=0');

        return $r;
    }
}
