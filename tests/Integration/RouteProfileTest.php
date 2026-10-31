<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityConfig;
use App\Event\EventOccurrence;
use App\Event\EventTypeRegistry;
use App\Event\Type\HalloweenType;
use App\Listing\ListingRepository;
use App\Routing\RoutePlanner;
use App\Routing\RoutingProviderInterface;
use App\Routing\RoutingUnavailableException;
use App\Security\Throttle;
use App\Shared\ValidationException;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FixtureRoutingProvider;
use App\Tests\Support\TestEventType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module tests/Integration/RouteProfileTest
 * @description Route profiles (Phase 2 plumbing, no UI yet): walking stays the default with its 0.9 cache key; driving
 *   is refused unless the event type allows it, the active event's settings enable it (route_modes) and a provider is
 *   configured; a driving route asks the provider's driving profile and is cached apart from walking; separate
 *   walking groups are never joined on foot. Without a provider nothing is drawn.
 * @dependencies tests/Support/AppTestCase, tests/Support/TestEventType, Routing/RoutePlanner
 */
final class RouteProfileTest extends AppTestCase
{
    public function testWalkingIsTheDefaultAndKeepsItsCacheKey(): void
    {
        FixtureRoutingProvider::$calls = 0;
        FixtureRoutingProvider::$profiles = [];
        $this->configureCommunity(['routing' => ['groups' => [['west', 'east']], 'provider_enabled' => true, 'crossings_verified' => true]]);
        [$a, $b] = $this->homes();
        $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b]]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('walking', $r['route']['profile']);
        self::assertSame(['foot-walking'], FixtureRoutingProvider::$profiles);
        $points = (string) json_encode(array_map(static fn (array $h) => ['lat' => $h['lat'], 'lon' => $h['lon']], [$this->home($a), $this->home($b)]));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM route_cache WHERE fingerprint = ?', [hash('sha256', 'fixture|foot-walking|'.$points)]), 'the 0.9 cache key');
        $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b], 'profile' => 'flying']);
        self::assertSame(422, $this->code());
    }

    public function testDrivingIsRefusedForHalloweenWhateverTheSettingsSay(): void
    {
        FixtureRoutingProvider::$calls = 0;
        $this->configureCommunity(['routing' => ['groups' => [['west', 'east']], 'provider_enabled' => true, 'crossings_verified' => true]]);
        $this->db()->executeStatement("UPDATE event_occurrences SET settings = '{\"route_modes\": [\"walking\", \"driving\"]}' WHERE state = 'active'");
        [$a, $b] = $this->homes();
        $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b], 'profile' => 'driving']);
        self::assertSame(409, $this->code());
        self::assertSame('routing_unavailable', $r['error']['code']);
        self::assertSame(0, FixtureRoutingProvider::$calls, 'Halloween routes are walking routes');
    }

    public function testDrivingNeedsTheTypeTheEventSettingAndAProvider(): void
    {
        FixtureRoutingProvider::$calls = 0;
        FixtureRoutingProvider::$profiles = [];
        $this->configureCommunity(['routing' => ['groups' => [['west'], ['east']], 'provider_enabled' => true, 'crossings_verified' => true]]);
        [$a, , $c] = $this->homes();
        $base = $this->repo()->current();
        self::assertNotNull($base->event);
        $planner = $this->planner();
        $with = fn (array $routeModes): CommunityConfig => self::lightsCommunity($base, $routeModes);

        self::assertFalse($planner->enabled($with(['walking']), 'driving'), 'the organizer did not enable driving');
        self::assertTrue($planner->enabled($with(['walking', 'driving']), 'driving'));
        self::assertFalse($planner->enabled(self::lightsCommunity($base, ['walking', 'driving'], false), 'driving'), 'the provider is not enabled');
        FixtureRoutingProvider::$available = false;
        try {
            self::assertFalse($planner->enabled($with(['walking', 'driving']), 'driving'), 'no provider configured');
        } finally {
            FixtureRoutingProvider::$available = true;
        }
        try {
            $planner->plan([$a, $c], $with(['walking']), 'driving');
            self::fail('driving must be refused without the event setting');
        } catch (RoutingUnavailableException $e) {
            self::assertStringContainsString('Driving directions are not available', $e->getMessage());
        }
        try {
            $planner->plan([$a, $c], $with(['walking', 'driving']), 'walking');
            self::fail('walking never joins separate groups');
        } catch (ValidationException $e) {
            self::assertStringContainsString('separate areas', $e->fields['stops']);
        }
        $route = $planner->plan([$a, $c], $with(['walking', 'driving']), 'driving');
        self::assertSame('driving', $route['profile']);
        self::assertSame(['driving-car'], FixtureRoutingProvider::$profiles, 'the driving profile, across areas');
        self::assertSame(['driving-car'], $this->db()->fetchFirstColumn('SELECT profile FROM route_cache'), 'cached apart from walking');
        self::assertStringContainsString('Follow traffic rules', $route['notice']);
    }

    private function planner(): RoutePlanner
    {
        $c = static::getContainer();

        return new RoutePlanner($c->get(RoutingProviderInterface::class), $c->get(ListingRepository::class), $c->get(Connection::class), $c->get(ClockInterface::class),
            $c->get(Throttle::class), new EventTypeRegistry([new HalloweenType(), new TestEventType('holiday_lights', 'nightly_range', ['walking', 'driving'])]));
    }

    /** The test community as a lights event (a type no release enables yet) with the given route modes. */
    private static function lightsCommunity(CommunityConfig $c, array $routeModes, bool $providerEnabled = true): CommunityConfig
    {
        $e = $c->event;
        \assert(null !== $e);
        $settings = array_replace_recursive($c->settings, ['event' => ['type' => 'holiday_lights'], 'routing' => ['provider_enabled' => $providerEnabled]]);
        $event = new EventOccurrence($e->id, 'holiday_lights', $e->name, $e->startsOn, $e->endsOn, $e->defaultStart, $e->defaultEnd, ['route_modes' => $routeModes], 'active');

        return new CommunityConfig($settings, $c->boundary, $c->boundaryApproved, $c->boundaryVersion, $c->zoneShapes, true, $event);
    }

    /** @return array<string,mixed> */
    private function home(int $id): array
    {
        return $this->json('GET', '/api/homes/'.$id)['home'];
    }

    /** @return list<int> */
    private function homes(): array
    {
        $ids = [];
        foreach ([['1', 40.003, -75.008, 'west'], ['3', 40.004, -75.006, 'west'], ['5', 40.004, -74.995, 'east']] as [$n, $lat, $lon, $z]) {
            $id = $this->addAddress($n, 'Pumpkin Ln', $lat, $lon, $z);
            $this->confirm($this->requestLink("p$n@example.test", ['address' => $this->byId($id), 'listing' => self::listing()]));
            $this->client->getCookieJar()->clear();
            $ids[] = $id;
        }

        return $ids;
    }
}
