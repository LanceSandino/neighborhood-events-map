<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Event\EventType;
use App\Event\EventTypeRegistry;
use App\Twig\EventTypeExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Twig/EventTypeExtensionTest
 * @description Templates ask the event type through event_type() and include only whitelisted fragments that lie
 *   under templates/event/<key>/ (event_fragment()); unknown names and stray paths are refused.
 * @dependencies Twig/EventTypeExtension, Event/EventTypeRegistry
 */
#[CoversClass(EventTypeExtension::class)]
final class EventTypeExtensionTest extends TestCase
{
    public function testTemplatesGetTheTypesViewAndItsFragments(): void
    {
        $x = new EventTypeExtension(EventTypeRegistry::builtIn());
        self::assertSame(['event_type', 'event_fragment'], array_map(static fn ($f) => $f->getName(), $x->getFunctions()));
        $view = $x->eventType('halloween');
        self::assertSame(['key', 'name', 'copy', 'statuses', 'filters', 'legend', 'hours'], array_keys($view));
        self::assertSame('Halloween', $view['name']);
        self::assertSame('Find treat stops', $view['copy']['explore_label']);
        self::assertSame($view, $x->eventType(null), 'no key means the default type');
        self::assertSame('event/halloween/_card_tags.html.twig', $x->fragment('halloween', 'card_tags'));
        $this->expectException(\InvalidArgumentException::class);
        $x->fragment('holiday_lights', 'card_tags');
    }

    public function testAFragmentOutsideTheTypesFolderIsRefused(): void
    {
        $stray = $this->createStub(EventType::class);
        $stray->method('key')->willReturn('halloween');
        $stray->method('fragments')->willReturn(['card_tags' => 'event/halloween/../../base.html.twig', 'other' => 'home/_card.html.twig']);
        foreach (['card_tags', 'other'] as $name) {
            try {
                EventTypeExtension::path($stray, $name);
                self::fail("$name must be refused");
            } catch (\LogicException $e) {
                self::assertStringContainsString('lies outside templates/event/halloween/', $e->getMessage());
            }
        }
    }
}
