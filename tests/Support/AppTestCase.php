<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Address\AddressNormalizer;
use App\Address\AddressRegistry;
use App\Community\CommunityRepository;
use App\Security\ProofOfWork;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

/**
 * @module tests/Support/AppTestCase
 * @description Integration harness: real PostgreSQL test DB (truncated per test, event tables included), frozen clock,
 *     a generic fictional test community (zones "west"/"east" + non-claimable "park"), PoW solver,
 *     email-link extraction and JSON helpers. Requests carry a same-origin Origin header.
 */
abstract class AppTestCase extends WebTestCase
{
    use ClockSensitiveTrait;

    /** Test env uses Symfony's mock session storage (cookie MOCKSESSID); prod uses SESSION_COOKIE_NAME. */
    protected const SESSION_COOKIE = 'MOCKSESSID';

    protected KernelBrowser $client;
    protected MockClock $clock;

    protected const BOUNDARY = [[-75.01, 40.00], [-74.99, 40.00], [-74.99, 40.02], [-75.01, 40.02], [-75.01, 40.00]];
    protected const WEST = [[-75.01, 40.00], [-75.00, 40.00], [-75.00, 40.015], [-75.01, 40.015], [-75.01, 40.00]];
    protected const EAST = [[-75.00, 40.00], [-74.99, 40.00], [-74.99, 40.015], [-75.00, 40.015], [-75.00, 40.00]];
    protected const PARK = [[-75.01, 40.015], [-74.99, 40.015], [-74.99, 40.02], [-75.01, 40.02], [-75.01, 40.015]];

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_ORIGIN' => 'http://localhost', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->clock = static::mockTime('2026-10-31 22:00:00 UTC'); // 6 PM America/New_York on Halloween
        $this->db()->executeStatement('TRUNCATE community_settings, community_geometries, eligible_addresses, users, claims, claim_intents,
            listings, auth_link_tokens, pow_challenges, throttle_events, disputes, audit_events, sessions, route_cache, account_ledger,
            event_occurrences, event_listings, event_listing_windows RESTART IDENTITY CASCADE');
        $this->repo()->reset();
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    protected function repo(): CommunityRepository
    {
        return static::getContainer()->get(CommunityRepository::class);
    }

    /** @param array<string,mixed> $overrides */
    protected function configureCommunity(array $overrides = [], bool $approve = true, bool $withZones = true): void
    {
        $settings = array_replace_recursive([
            'community' => ['id' => 'testville', 'name' => 'Testville', 'locality' => 'Testtown', 'region' => 'PA', 'timezone' => 'America/New_York'],
            'event' => ['date' => '2026-10-31', 'starts_at' => '17:00', 'ends_at' => '21:00'],
            'map' => ['center' => ['lat' => 40.01, 'lon' => -75.0]],
            'zones' => $withZones ? [
                ['key' => 'west', 'label' => 'West side'],
                ['key' => 'east', 'label' => 'East side'],
                ['key' => 'park', 'label' => 'Park', 'claimable' => false, 'residential' => false],
            ] : [],
            'registration' => ['address_mode' => 'both', 'street_allowlist' => ['Pumpkin Ln', 'Lantern Way']],
        ], $overrides);
        $this->repo()->saveSettings($settings, null);
        $features = [['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => [self::BOUNDARY]]]];
        if ($withZones) {
            foreach (['west' => self::WEST, 'east' => self::EAST, 'park' => self::PARK] as $k => $ring) {
                $features[] = ['type' => 'Feature', 'properties' => ['zone_key' => $k], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]];
            }
        }
        $this->repo()->replaceGeometry(['type' => 'FeatureCollection', 'features' => $features], null, $approve, true);
        $this->repo()->reset();
    }

    protected function addAddress(string $house, string $street, float $lat, float $lon, ?string $zone = null): int
    {
        $parts = (new AddressNormalizer())->fromParts($house, $street);

        return static::getContainer()->get(AddressRegistry::class)->insert($parts, $zone, $lat, $lon, 'import', true, true);
    }

    /** Claim or review target by registry id, with the house number a visitor looked up (the server matches both). */
    protected function byId(int $addressId): array
    {
        return ['address_id' => $addressId, 'house_number' => (string) $this->db()->fetchOne('SELECT house_number FROM eligible_addresses WHERE id = ?', [$addressId])];
    }

    /** @return list<bool> claimed flag of each registry lookup result for $query */
    protected function claimedFlags(string $query): array
    {
        return array_column($this->json('GET', '/api/addresses/lookup?q='.rawurlencode($query))['results'], 'claimed');
    }

    /** @param array<string,mixed>|null $body */
    protected function json(string $method, string $uri, ?array $body = null, array $headers = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->client->request($method, $uri, [], [], $server, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (json_decode($content, true) ?? ['_raw' => $content]);
    }

    protected function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** Requests a challenge and brute-forces it like the browser worker (test difficulty is low). */
    protected function solvePow(string $purpose): array
    {
        $c = $this->json('GET', '/api/pow?purpose='.$purpose)['challenge'];
        for ($nonce = 0; $nonce < 50_000_000; ++$nonce) {
            if (ProofOfWork::meetsDifficulty(ProofOfWork::digest($purpose, $c['id'], $c['salt'], (string) $nonce), $c['difficulty'])) {
                return ['id' => $c['id'], 'nonce' => (string) $nonce];
            }
        }
        self::fail('PoW not solved');
    }

    /** Sends a sign-in/claim link request and returns the raw token from the email. */
    protected function requestLink(string $email, ?array $claim = null): string
    {
        $body = ['email' => $email, 'pow' => $this->solvePow(null === $claim ? 'login' : 'claim')];
        if (null !== $claim) {
            $body['claim'] = $claim;
        }
        $r = $this->json('POST', '/api/auth/request-link', $body);
        self::assertSame(202, $this->code(), json_encode($r));
        $messages = self::getMailerMessages();
        self::assertNotEmpty($messages, 'an email was sent');
        $message = end($messages);
        \assert($message instanceof Email);
        self::assertSame($email, $message->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('~/auth/confirm\?t=([A-Za-z0-9_-]{43})~', (string) $message->getTextBody());
        preg_match('~/auth/confirm\?t=([A-Za-z0-9_-]{43})~', (string) $message->getTextBody(), $m);

        return $m[1];
    }

    /** GET the confirmation page, then POST it (the only consuming step). */
    protected function confirm(string $token): void
    {
        $crawler = $this->client->request('GET', '/auth/confirm?t='.$token);
        self::assertSame(200, $this->code());
        $form = $crawler->filter('form[action="/auth/confirm"]')->form();
        $this->client->submit($form);
    }

    protected function signIn(string $email): void
    {
        $this->confirm($this->requestLink($email));
        self::assertSame(303, $this->code());
    }

    protected function csrf(): string
    {
        $crawler = $this->client->request('GET', '/me');
        self::assertSame(200, $this->code());

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    /** @return array<string,mixed> */
    protected static function listing(array $over = []): array
    {
        return $over + ['start' => '17:30', 'end' => '20:30', 'candy' => true, 'low_noise' => true, 'scare_level' => 'gentle', 'note' => 'Porch light on.'];
    }
}
