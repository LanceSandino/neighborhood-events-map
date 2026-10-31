<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Community\CommunityConfig;
use App\Community\CommunitySchema;
use App\Event\EventType;
use App\Event\EventTypeRegistry;
use App\Event\Type\GarageSaleType;
use App\Event\Type\HalloweenType;
use App\Event\Type\HolidayLightsType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/EventTypeRegistryTest
 * @description The event-type registry (ADR 0010, D-20261031-01): reserved keys never change, Halloween, Christmas
 *   lights and (since 0.13) the garage sale are enabled, an unknown or not-yet-enabled key is a clear configuration
 *   problem (a registry built with fewer enabled keys stands for a version that does not offer a type yet), and the
 *   registry refuses implementations whose key is not reserved or that register twice.
 * @dependencies Event/EventTypeRegistry, Event/Type/HalloweenType, Event/Type/HolidayLightsType, Event/Type/GarageSaleType
 */
#[CoversClass(EventTypeRegistry::class)]
final class EventTypeRegistryTest extends TestCase
{
    public function testKeysAreReservedOnceAndEveryReservedTypeIsEnabled(): void
    {
        self::assertSame(['halloween', 'holiday_lights', 'garage_sale'], EventTypeRegistry::KEYS, 'keys are stored with events and listings: never renamed or reused');
        self::assertSame(['halloween', 'holiday_lights', 'garage_sale'], EventTypeRegistry::ENABLED, '0.12 enables Christmas lights, 0.13 the garage sale (ROADMAP Stages 3 and 4)');
        self::assertSame('halloween', EventTypeRegistry::DEFAULT);
        $registry = EventTypeRegistry::builtIn();
        self::assertSame(['halloween', 'holiday_lights', 'garage_sale'], array_map(static fn (EventType $t) => $t->key(), $registry->enabled()));
        self::assertInstanceOf(HalloweenType::class, $registry->get('halloween'));
        self::assertInstanceOf(HolidayLightsType::class, $registry->get('holiday_lights'));
        self::assertInstanceOf(GarageSaleType::class, $registry->get('garage_sale'));
        foreach (EventTypeRegistry::KEYS as $key) {
            self::assertTrue($registry->isEnabled($key), $key);
        }
    }

    public function testUnknownAndNotYetEnabledTypesAreConfigurationProblems(): void
    {
        $registry = EventTypeRegistry::builtIn();
        self::assertNull($registry->problem('halloween'));
        self::assertSame('event.type "christmas" is not a known event type (halloween, holiday_lights, garage_sale).', $registry->problem('christmas'));
        self::assertNull($registry->problem('holiday_lights'));
        self::assertNull($registry->problem('garage_sale'));
        self::assertNotNull($registry->problem('Halloween'), 'keys are exact');
        // A version that does not offer the garage sale yet (0.12): a clear problem, and only the offered types are enabled.
        $older = new EventTypeRegistry([new HalloweenType(), new HolidayLightsType()], ['halloween', 'holiday_lights']);
        self::assertSame('event.type "garage_sale" is not available in this version; choose halloween or holiday_lights.', $older->problem('garage_sale'));
        self::assertFalse($older->isEnabled('garage_sale'));
        self::assertSame(['halloween', 'holiday_lights'], array_map(static fn (EventType $t) => $t->key(), $older->enabled()));
        self::assertFalse((new EventTypeRegistry([new HalloweenType(), new GarageSaleType()], ['halloween']))->isEnabled('garage_sale'), 'implemented but not offered');
        $this->expectException(\InvalidArgumentException::class);
        $older->get('garage_sale');
    }

    public function testTheCommunitysTypeComesFromItsSettingsAndDefaultsToHalloween(): void
    {
        $registry = EventTypeRegistry::builtIn();
        $settings = (new CommunitySchema())->normalize(['community' => ['id' => 'x1', 'name' => 'X'], 'event' => ['date' => '2026-10-31']]);
        self::assertSame('halloween', $registry->for(new CommunityConfig($settings, null, false, null, []))->key());
        unset($settings['event']['type']); // settings saved before event.type existed
        self::assertSame('halloween', (new CommunityConfig($settings, null, false, null, []))->eventTypeKey());
    }

    public function testAnImplementationNeedsAReservedKeyAndRegistersOnce(): void
    {
        $stray = $this->createStub(EventType::class);
        $stray->method('key')->willReturn('fireworks');
        try {
            new EventTypeRegistry([new HalloweenType(), $stray]);
            self::fail('an unreserved key is refused');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"fireworks" is not reserved', $e->getMessage());
        }
        try {
            new EventTypeRegistry([new HalloweenType(), new HalloweenType()]);
            self::fail('a key registers once');
        } catch (\LogicException $e) {
            self::assertStringContainsString('registered twice', $e->getMessage());
        }
        try {
            new EventTypeRegistry([new HalloweenType(), new HolidayLightsType()]); // the garage sale is enabled but missing
            self::fail('an enabled type needs an implementation');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"garage_sale" is enabled but has no implementation', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        new EventTypeRegistry([]); // an enabled type without an implementation
    }
}
