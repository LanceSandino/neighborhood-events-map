<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\CommunitySchema;
use App\Community\ConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Yaml\Yaml;

/**
 * Traceability: OPEN_SOURCE_AND_RELEASES (config-only customization), ACCEPTANCE #13/#18.
 */
#[CoversClass(CommunitySchema::class)]
final class CommunitySchemaTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function testShippedConfigsNormalize(): void
    {
        $s = new CommunitySchema();
        $generic = $s->normalize(Yaml::parseFile(self::root().'/config/community.example.yaml'));
        self::assertSame([], $generic['zones']);
        self::assertFalse($generic['features']['optional_zones']);

        $ventura = $s->normalize(Yaml::parseFile(self::root().'/examples/ventura-reserve/community.yaml'));
        self::assertSame(['phase-1', 'phase-2', 'phase-3'], array_column($ventura['zones'], 'key'));
        self::assertSame(['Phase 1', 'Phase 2', 'Phase 3'], array_column(array_filter($ventura['zones'], fn ($z) => $z['claimable']), 'label'));
        self::assertSame('registry', $ventura['registration']['address_mode']);
        self::assertSame([], $ventura['routing']['groups']);
        self::assertFalse($ventura['routing']['crossings_verified']);
        self::assertSame('America/New_York', $ventura['community']['timezone']);
        self::assertSame(30, $ventura['registration']['session_idle_minutes']);
        self::assertStringEndsNotWith('-demo', $ventura['community']['id'], 'demo homes can never be seeded into the real-data example');

        $demo = $s->normalize(Yaml::parseFile(self::root().'/examples/demo/community.yaml'));
        self::assertSame([], $demo['zones']);
        self::assertSame('#2E7D6B', $demo['branding']['primary_color']);
        self::assertSame('2026-10-30', $demo['event']['date']);
        self::assertSame(['PUMPKIN PATCH LN', 'LANTERN WAY', 'COBWEB CT', 'MOONBEAM DR'], $demo['registration']['street_allowlist']);
    }

    public function testCollectsAllErrors(): void
    {
        try {
            (new CommunitySchema())->normalize([
                'community' => ['id' => 'Bad Id', 'name' => '', 'timezone' => 'Mars/Base'],
                'event' => ['date' => '2026-13-40', 'starts_at' => '21:00', 'ends_at' => '17:00'],
                'branding' => ['primary_color' => 'orange'],
                'map' => ['tiles' => ['url' => 'http://insecure/{z}/{x}/{y}.png']],
                'zones' => [['key' => 'Phase 1'], ['key' => 'east'], ['key' => 'east']],
                'registration' => ['address_mode' => 'self_entry', 'email_magic_link_minutes' => -5, 'session_idle_minutes' => 99999],
                'routing' => ['groups' => [['nope']]],
            ]);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            $joined = implode("\n", $e->errors);
            foreach (['community.id', 'community.name', 'timezone', 'event.date', 'starts_at', 'primary_color', 'tiles.url', 'zones[0].key', 'duplicated', 'street_allowlist', 'email_magic_link_minutes must be between 5 and 60', 'session_idle_minutes must be between 5 and 240', 'unknown zone "nope"'] as $needle) {
                self::assertStringContainsString($needle, $joined);
            }
        }
    }

    public function testNonNumericZonesAndDefaults(): void
    {
        $s = (new CommunitySchema())->normalize([
            'community' => ['id' => 'lakeside', 'name' => 'Lakeside', 'timezone' => 'America/Chicago'],
            'event' => ['date' => '2027-10-31'],
            'zones' => [['key' => 'lakefront', 'label' => 'Lakefront'], ['key' => 'section-b', 'label' => 'Section B', 'claimable' => false]],
        ]);
        self::assertSame(['lakefront', 'section-b'], array_column($s['zones'], 'key'));
        self::assertTrue($s['features']['optional_zones']);
        self::assertFalse($s['zones'][1]['residential']);
        self::assertSame('17:00', $s['event']['starts_at']);
        self::assertSame('registry', $s['registration']['address_mode']);
    }
}
