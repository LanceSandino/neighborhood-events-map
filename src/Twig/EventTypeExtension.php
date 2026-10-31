<?php

declare(strict_types=1);

namespace App\Twig;

use App\Event\EventType;
use App\Event\EventTypeRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @module Twig/EventTypeExtension
 * @description Lets templates ask the community's event type instead of branching on it: event_type(key) gives
 *     its name, copy, statuses, filters, legend and hour choices; event_fragment(key, name) gives a template
 *     path only from the type's whitelist (templates/event/<key>/…), so no other template can be included.
 *
 * @dependencies Event/EventTypeRegistry, Twig
 */
final class EventTypeExtension extends AbstractExtension
{
    public function __construct(private readonly EventTypeRegistry $types)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('event_type', [$this, 'eventType']),
            new TwigFunction('event_fragment', [$this, 'fragment']),
        ];
    }

    /** @return array<string,mixed> */
    public function eventType(?string $key): array
    {
        $t = $this->types->get($key ?? EventTypeRegistry::DEFAULT);

        return ['key' => $t->key(), 'name' => $t->defaultName(), 'copy' => $t->copy(), 'statuses' => $t->statuses(),
            'filters' => $t->filters(), 'legend' => $t->legend(), 'hours' => $t->hourChoices()];
    }

    public function fragment(?string $key, string $name): string
    {
        $t = $this->types->get($key ?? EventTypeRegistry::DEFAULT);

        return self::path($t, $name);
    }

    /** @throws \InvalidArgumentException for a name the type does not whitelist */
    public static function path(EventType $type, string $name): string
    {
        $path = $type->fragments()[$name] ?? throw new \InvalidArgumentException(sprintf('Event type "%s" has no "%s" fragment.', $type->key(), $name));
        if (!str_starts_with($path, 'event/'.$type->key().'/_') || !str_ends_with($path, '.html.twig') || str_contains($path, '..')) {
            throw new \LogicException(sprintf('Fragment "%s" of event type "%s" lies outside templates/event/%s/.', $name, $type->key(), $type->key()));
        }

        return $path;
    }
}
