<?php

declare(strict_types=1);

namespace App\Twig;

use App\Community\CommunityRepository;
use App\Event\EventDates;
use App\Event\EventOccurrence;
use App\Event\PublicEvents;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @module Twig/EventSwitcherExtension
 * @description Several public events per community (ADR 0011): event_tabs(path) gives the switcher's tabs for the
 *     map ('/') or My house ('/me') — none while only one event is public —; event_query() gives "?e=<id>" while the
 *     page shows an event other than the default (so the page's own links stay on that event) and '' otherwise;
 *     event_dates(event) gives an event's dates in words ("Oct 31, 2027, tentative", "dates to be announced").
 *
 * @dependencies Community/CommunityRepository, Event/PublicEvents, Event/EventDates, Twig
 */
final class EventSwitcherExtension extends AbstractExtension
{
    public function __construct(private readonly PublicEvents $events, private readonly CommunityRepository $community)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('event_tabs', [$this->events, 'tabs']),
            new TwigFunction('event_query', [$this, 'query']),
            new TwigFunction('event_dates', [$this, 'dates']),
        ];
    }

    public function query(): string
    {
        $selected = $this->community->selected();

        return null === $selected || $selected->isDefault ? '' : '?e='.$selected->id;
    }

    /** @param array<string,mixed>|null $event the public event payload (c.event) */
    public function dates(?array $event): string
    {
        if (null === $event || !\is_string($event['starts_on'] ?? null)) {
            return EventDates::TBD;
        }

        return EventDates::words(new EventOccurrence(0, (string) ($event['type'] ?? ''), '', $event['starts_on'], (string) ($event['ends_on'] ?? $event['starts_on']), '00:00', '00:01', [],
            (string) ($event['state'] ?? ''), datesTentative: (bool) ($event['dates_tentative'] ?? false)));
    }
}
