<?php

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Routing\OpenRouteServiceProvider;
use App\Routing\RoutingUnavailableException;
use App\Shared\RuntimeConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Traceability: BLUEPRINT §9 (server-side pedestrian provider, key never sent to browsers), ACCEPTANCE #15 (fixtures).
 */
#[CoversClass(OpenRouteServiceProvider::class)]
final class OpenRouteServiceProviderTest extends TestCase
{
    private static function config(string $provider = 'openrouteservice', string $key = 'secret-key'): RuntimeConfig
    {
        return new RuntimeConfig('http://x', 'a@b.c', 'a@b.c', 's', '', '', 16, 120, [], $provider, $key, 'https://ors.example', false);
    }

    public function testRequestsFootWalkingWithLonLatAndParsesSummary(): void
    {
        $fixture = ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-74.99, 40.03], [-74.989, 40.031]]],
            'properties' => ['summary' => ['distance' => 180.4, 'duration' => 130.2]]]]];
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen, $fixture) {
            $seen = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true), 'headers' => $options['headers']];

            return new MockResponse(json_encode($fixture));
        });
        $r = (new OpenRouteServiceProvider($client, self::config()))->walkingRoute([['lat' => 40.03, 'lon' => -74.99], ['lat' => 40.031, 'lon' => -74.989]]);
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://ors.example/v2/directions/foot-walking/geojson', $seen['url']);
        self::assertSame([[-74.99, 40.03], [-74.989, 40.031]], $seen['body']['coordinates']);
        self::assertContains('Authorization: secret-key', $seen['headers']);
        self::assertSame(180.4, $r['distance_m']);
        self::assertSame('LineString', $r['geometry']['type']);
    }

    public function testUnavailableWithoutKeyAndOnProviderErrors(): void
    {
        self::assertFalse((new OpenRouteServiceProvider(new MockHttpClient(), self::config('openrouteservice', '')))->available());
        self::assertFalse((new OpenRouteServiceProvider(new MockHttpClient(), self::config('none')))->available());
        foreach ([new MockResponse('nope', ['http_code' => 500]), new MockResponse(json_encode(['features' => []]))] as $resp) {
            try {
                (new OpenRouteServiceProvider(new MockHttpClient($resp), self::config()))->walkingRoute([['lat' => 1, 'lon' => 1], ['lat' => 2, 'lon' => 2]]);
                self::fail('expected RoutingUnavailableException');
            } catch (RoutingUnavailableException) {
                self::assertTrue(true);
            }
        }
    }
}
