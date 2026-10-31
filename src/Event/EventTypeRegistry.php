<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunityConfig;
use App\Event\Type\GarageSaleType;
use App\Event\Type\HalloweenType;
use App\Event\Type\HolidayLightsType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * @module Event/EventTypeRegistry
 * @description The code-defined event types, by immutable key (D-20261031-01). Every implementation is a tagged
 *     service; ENABLED lists the keys organizers may choose in this version (Halloween, since 0.12 Christmas lights,
 *     since 0.13 the garage sale). A key is never renamed or reused: it is stored with every event and listing. A
 *     registry may be built with fewer enabled keys (tests of a type a version does not offer yet).
 *
 * @dependencies Event/EventType (tagged services), Community/CommunityConfig
 */
final class EventTypeRegistry
{
    /** Every key ever reserved for an event type. Immutable. */
    public const KEYS = ['halloween', 'holiday_lights', 'garage_sale'];
    /** Keys an organizer may choose in this version. */
    public const ENABLED = ['halloween', 'holiday_lights', 'garage_sale'];
    /** The event type of a community whose settings name none (everything before event.type existed). */
    public const DEFAULT = 'halloween';

    /** @var array<string, EventType> */
    private array $types = [];
    /** @var list<string> */
    private readonly array $enabledKeys;

    /**
     * @param iterable<EventType> $types
     * @param list<string>        $enabled the keys organizers may choose (ENABLED unless narrowed)
     */
    public function __construct(#[AutowireIterator(EventType::TAG)] iterable $types, array $enabled = self::ENABLED)
    {
        $this->enabledKeys = array_values(array_intersect(self::ENABLED, $enabled));
        foreach ($types as $type) {
            $key = $type->key();
            if (!\in_array($key, self::KEYS, true)) {
                throw new \LogicException(sprintf('Event type key "%s" is not reserved in EventTypeRegistry::KEYS.', $key));
            }
            if (isset($this->types[$key])) {
                throw new \LogicException(sprintf('Event type "%s" is registered twice.', $key));
            }
            $this->types[$key] = $type;
        }
        foreach ($this->enabledKeys as $key) {
            if (!isset($this->types[$key])) {
                throw new \LogicException(sprintf('Event type "%s" is enabled but has no implementation.', $key));
            }
        }
    }

    /** The registry without a container (value objects, CLI defaults, unit tests): the same built-in types. */
    public static function builtIn(): self
    {
        return new self([new HalloweenType(), new HolidayLightsType(), new GarageSaleType()]);
    }

    /** @throws \InvalidArgumentException for a key without an implementation */
    public function get(string $key): EventType
    {
        return $this->types[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown event type "%s".', $key));
    }

    /** The type of the community's event. */
    public function for(CommunityConfig $community): EventType
    {
        return $this->get($community->eventTypeKey());
    }

    public function isEnabled(string $key): bool
    {
        return \in_array($key, $this->enabledKeys, true) && isset($this->types[$key]);
    }

    /** @return list<EventType> the types organizers may choose, in ENABLED order */
    public function enabled(): array
    {
        return array_values(array_map(fn (string $k) => $this->types[$k], array_filter($this->enabledKeys, fn (string $k) => isset($this->types[$k]))));
    }

    /** Why a configured event.type cannot be used, or null when it can. */
    public function problem(string $key): ?string
    {
        if (!\in_array($key, self::KEYS, true)) {
            return sprintf('event.type "%s" is not a known event type (%s).', $key, implode(', ', self::KEYS));
        }
        if (!$this->isEnabled($key)) {
            return sprintf('event.type "%s" is not available in this version; choose %s.', $key, implode(' or ', $this->enabledKeys));
        }

        return null;
    }
}
