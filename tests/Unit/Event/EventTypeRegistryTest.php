<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Community\CommunityConfig;
use App\Community\CommunitySchema;
use App\Event\EventType;
use App\Event\EventTypeRegistry;
use App\Event\Type\HalloweenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/EventTypeRegistryTest
 * @description The event-type registry (ADR 0010, D-20261031-01): reserved keys never change, only Halloween is
 *   enabled in this version, an unknown or not-yet-enabled key is a clear configuration problem, and the
 *   registry refuses implementations whose key is not reserved or that register twice.
 * @dependencies Event/EventTypeRegistry, Event/Type/HalloweenType
 */
#[CoversClass(EventTypeRegistry::class)]
final class EventTypeRegistryTest extends TestCase
{
    public function testKeysAreReservedOnceAndOnlyHalloweenIsEnabled(): void
    {
        self::assertSame(['halloween', 'holiday_lights', 'garage_sale'], EventTypeRegistry::KEYS, 'keys are stored with events and listings: never renamed or reused');
        self::assertSame(['halloween'], EventTypeRegistry::ENABLED);
        self::assertSame('halloween', EventTypeRegistry::DEFAULT);
        $registry = EventTypeRegistry::builtIn();
        self::assertSame(['halloween'], array_map(static fn (EventType $t) => $t->key(), $registry->enabled()));
        self::assertInstanceOf(HalloweenType::class, $registry->get('halloween'));
        self::assertTrue($registry->isEnabled('halloween'));
        self::assertFalse($registry->isEnabled('holiday_lights'));
        self::assertFalse($registry->isEnabled('garage_sale'));
    }

    public function testUnknownAndNotYetEnabledTypesAreConfigurationProblems(): void
    {
        $registry = EventTypeRegistry::builtIn();
        self::assertNull($registry->problem('halloween'));
        self::assertSame('event.type "christmas" is not a known event type (halloween, holiday_lights, garage_sale).', $registry->problem('christmas'));
        self::assertSame('event.type "holiday_lights" is not available in this version; choose halloween.', $registry->problem('holiday_lights'));
        self::assertSame('event.type "garage_sale" is not available in this version; choose halloween.', $registry->problem('garage_sale'));
        self::assertNotNull($registry->problem('Halloween'), 'keys are exact');
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('holiday_lights');
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
        $this->expectException(\LogicException::class);
        new EventTypeRegistry([]); // an enabled type without an implementation
    }
}
