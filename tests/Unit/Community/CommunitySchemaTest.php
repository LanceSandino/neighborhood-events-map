<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\CommunitySchema;
use App\Community\ConfigException;
use App\Event\EventTypeRegistry;
use App\Event\Type\HalloweenType;
use App\Event\Type\HolidayLightsType;
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

    public function testTheEventNameDefaultsToHalloweenAndIsTheOrganizersToChange(): void
    {
        $base = ['community' => ['id' => 'lakeside', 'name' => 'Lakeside'], 'event' => ['date' => '2027-12-18']];
        $schema = new CommunitySchema();
        self::assertSame('Halloween', $schema->normalize($base)['event']['name'], 'the first supported event type');
        self::assertSame('Holiday Lights', $schema->normalize(array_replace_recursive($base, ['event' => ['name' => ' Holiday Lights ']]))['event']['name']);
        self::assertSame('Halloween', $schema->normalize(array_replace_recursive($base, ['event' => ['name' => '']]))['event']['name'], 'empty means the default');
        self::assertSame(40, mb_strlen($schema->normalize(array_replace_recursive($base, ['event' => ['name' => str_repeat('x', 90)]]))['event']['name']));
        // The same cleaning as every other community text (Shared/TextInput): no controls, no hidden or
        // reordering characters, valid UTF-8 only.
        $named = fn (string $name) => $schema->normalize(array_replace_recursive($base, ['event' => ['name' => $name]]))['event']['name'];
        self::assertSame('Spring Fair', $named("Spring\nFair"));
        self::assertSame('SpringFair', $named("Spring\u{202E}Fair\u{200B}"));
        self::assertSame('Halloween', $named("\u{200B}\u{FEFF}"), 'only invisible characters means the default');
        self::assertSame('Halloween', $named("\xC3\x28"), 'invalid UTF-8 means the default');
        foreach (['config/community.example.yaml', 'examples/demo/community.yaml', 'examples/ventura-reserve/community.yaml'] as $file) {
            self::assertSame('Halloween', $schema->normalize(Yaml::parseFile(self::root().'/'.$file))['event']['name'], $file);
        }
    }

    public function testAboutAccessAndLinks(): void
    {
        $s = new CommunitySchema();
        $base = Yaml::parseFile(self::root().'/config/community.example.yaml');
        $base['community']['about'] = "First paragraph.\r\n\r\n\r\nSecond\x07 one.";
        $base['community']['access'] = 'gated_open';
        $base['community']['links'] = [['label' => 'Facebook group', 'url' => 'https://www.facebook.com/groups/example'], ['label' => '', 'url' => '']];
        $n = $s->normalize($base);
        self::assertSame("First paragraph.\n\nSecond  one.", $n['community']['about'], 'paragraphs kept, control characters removed');
        self::assertSame('gated_open', $n['community']['access']);
        self::assertSame([['label' => 'Facebook group', 'url' => 'https://www.facebook.com/groups/example']], $n['community']['links'], 'empty console rows are dropped');
        self::assertSame('unspecified', $s->normalize(Yaml::parseFile(self::root().'/config/community.example.yaml'))['community']['access']);

        $base['community']['access'] = 'open-house';
        $base['community']['links'] = [['label' => 'Bad', 'url' => 'javascript:alert(1)'], ['label' => '', 'url' => 'https://example.org']];
        try {
            $s->normalize($base);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            $joined = implode("\n", $e->errors);
            self::assertStringContainsString('community.access must be one of', $joined);
            self::assertStringContainsString('community.links[0] needs a label and an https:// or mailto: address', $joined);
            self::assertStringContainsString('community.links[1]', $joined);
        }
    }

    public function testZoneNamesCanBePinnedToAPointOnTheMap(): void
    {
        $s = new CommunitySchema();
        $base = Yaml::parseFile(self::root().'/examples/ventura-reserve/community.yaml');
        $base['zones'][0]['label_at'] = [-81.4512345678, 28.5123456789];
        $base['zones'][1]['label_at'] = null;
        $n = $s->normalize($base);
        self::assertSame([-81.451235, 28.512346], $n['zones'][0]['label_at'], 'stored as [longitude, latitude], about 10 cm precision');
        self::assertNull($n['zones'][1]['label_at']);
        self::assertNull($n['zones'][2]['label_at'], 'absent: the map picks the spot');

        foreach ([[28.5, -181.0], 'here', [1.0], [\NAN, 2.0], ['-81.4', '28.5'], [-81.4, 91.0]] as $bad) {
            $base['zones'][0]['label_at'] = $bad;
            try {
                $s->normalize($base);
                self::fail('expected ConfigException for '.json_encode($bad, \JSON_PARTIAL_OUTPUT_ON_ERROR));
            } catch (ConfigException $e) {
                self::assertStringContainsString('zones[0].label_at must be [longitude, latitude]', implode("\n", $e->errors));
            }
        }
    }

    public function testTheEventTypeDefaultsToHalloweenAndAnUnknownOrDisabledTypeIsRefused(): void
    {
        $schema = new CommunitySchema();
        $base = ['community' => ['id' => 'lakeside', 'name' => 'Lakeside'], 'event' => ['date' => '2027-10-31']];
        $n = $schema->normalize($base);
        self::assertSame('halloween', $n['event']['type']);
        self::assertSame(['name', 'date', 'starts_at', 'ends_at', 'type'], array_keys($n['event']));
        self::assertSame($n, $schema->normalize(array_replace_recursive($base, ['event' => ['type' => 'halloween']])));
        // The type supplies the defaults it always had: name, subtitle, branding and the feature switches.
        self::assertSame(['Halloween', 'Your neighborhood. Your Halloween.', '🎃', 'Little ghosts.', 'Big adventures.'],
            [$n['event']['name'], $n['community']['subtitle'], $n['branding']['brand_emoji'], $n['branding']['headline'], $n['branding']['headline_accent']]);
        self::assertSame(['candy', 'nonfood_treats', 'adult_refreshments', 'sensory_filters', 'optional_zones', 'walking_routes'], array_keys($n['features']));
        foreach (['config/community.example.yaml', 'examples/demo/community.yaml', 'examples/ventura-reserve/community.yaml'] as $file) {
            self::assertSame('halloween', $schema->normalize(Yaml::parseFile(self::root().'/'.$file))['event']['type'], $file);
        }
        foreach (['christmas' => 'is not a known event type', 'HALLOWEEN' => 'is not a known event type', 'rummage' => 'is not a known event type'] as $type => $why) {
            try {
                $schema->normalize(array_replace_recursive($base, ['event' => ['type' => $type]]));
                self::fail("expected ConfigException for $type");
            } catch (ConfigException $e) {
                self::assertSame([sprintf('event.type "%s" %s', $type, $why).' (halloween, holiday_lights, garage_sale).'], $e->errors);
            }
        }
        // A version that does not offer a type refuses it by name (0.12 and the garage sale).
        $older = new CommunitySchema(types: new EventTypeRegistry([new HalloweenType(), new HolidayLightsType()], ['halloween', 'holiday_lights']));
        try {
            $older->normalize(array_replace_recursive($base, ['event' => ['type' => 'garage_sale']]));
            self::fail('expected ConfigException for garage_sale in a version without it');
        } catch (ConfigException $e) {
            self::assertSame(['event.type "garage_sale" is not available in this version; choose halloween or holiday_lights.'], $e->errors);
        }
        // 0.13 offers the garage sale: its defaults ("Garage Sale", teal and coral), sale days and none of Halloween's switches.
        $sale = $schema->normalize(['community' => ['id' => 'x2', 'name' => 'X'], 'event' => ['type' => 'garage_sale', 'date' => '2027-04-10', 'ends_on' => '2027-04-11', 'starts_at' => '08:00', 'ends_at' => '14:00']]);
        self::assertSame(['Garage Sale', 'Good finds.', 'Close to home.', '#178D80', '#E77C5D', '2027-04-10', '2027-04-11'],
            [$sale['event']['name'], $sale['branding']['headline'], $sale['branding']['headline_accent'], $sale['branding']['primary_color'], $sale['branding']['accent_color'],
                $sale['event']['starts_on'], $sale['event']['ends_on']]);
        self::assertSame(['optional_zones', 'walking_routes'], array_keys($sale['features']));
        $rummage = $schema->normalize(Yaml::parseFile(self::root().'/examples/demo-sale/community.yaml'));
        self::assertSame(['garage_sale', 'Community Rummage Sale', '2027-04-10', '2027-04-11', 'maple-hollow-sale-demo'],
            [$rummage['event']['type'], $rummage['event']['name'], $rummage['event']['starts_on'], $rummage['event']['ends_on'], $rummage['community']['id']]);
        // 0.12 offers Christmas lights: its defaults, a date range and none of Halloween's switches.
        $lights = $schema->normalize(['community' => ['id' => 'x1', 'name' => 'X'], 'event' => ['type' => 'holiday_lights', 'date' => '2026-12-01', 'ends_on' => '2027-01-03']]);
        self::assertSame(['Christmas Lights', 'Holiday nights.', 'Bright lights.', '#38597F', '#CE9D3D', '2026-12-01', '2027-01-03'],
            [$lights['event']['name'], $lights['branding']['headline'], $lights['branding']['headline_accent'], $lights['branding']['primary_color'], $lights['branding']['accent_color'],
                $lights['event']['starts_on'], $lights['event']['ends_on']]);
        self::assertSame(['optional_zones', 'walking_routes'], array_keys($lights['features']));
        $demo = $schema->normalize(Yaml::parseFile(self::root().'/examples/demo-lights/community.yaml'));
        self::assertSame(['holiday_lights', '2026-12-01', '2027-01-03', 'maple-hollow-lights-demo'], [$demo['event']['type'], $demo['event']['starts_on'], $demo['event']['ends_on'], $demo['community']['id']]);
    }
}
