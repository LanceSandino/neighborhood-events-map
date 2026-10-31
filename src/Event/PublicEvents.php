<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunityRepository;

/**
 * @module Event/PublicEvents
 * @description The events visitors may see (upcoming, open, closed; of types this release offers), the default first:
 *     their public description for GET /api/events — id, type, name, state, dates, tentative, default and, for a
 *     closed event, its thank-you text — and the event switcher's tabs (emoji and name, the year when two events
 *     share a name, the state in words for an event that is not open, the link and whether it is the selected one).
 *     Drafts and archived events are never described (ADR 0011, D-20261031-40).
 *
 * @dependencies Community/CommunityRepository, Event/EventOccurrenceRepository, Event/EventTypeRegistry, Event/ClosingMessage, Event/EventDates
 */
final class PublicEvents
{
    public function __construct(
        private readonly CommunityRepository $community,
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventTypeRegistry $types,
    ) {
    }

    /** @return list<array<string,mixed>> the public events as GET /api/events describes them */
    public function list(): array
    {
        return array_map(fn (EventOccurrence $e) => $this->view($e), $this->community->publicEvents());
    }

    /** @return array<string,mixed> one public event's description */
    public function view(EventOccurrence $e): array
    {
        return ['id' => $e->id, 'type' => $e->type, 'name' => $e->name, 'state' => $e->state, 'starts_on' => $e->startsOn, 'ends_on' => $e->endsOn,
            'dates_tentative' => $e->datesTentative, 'is_default' => $e->isDefault]
            + (EventOccurrence::CLOSED === $e->state ? ['closing_message' => $this->closingMessage($e)] : []);
    }

    /** The thank-you text of a closed event (the organizer's, or the type's default naming next year's dates). */
    public function closingMessage(EventOccurrence $closed): string
    {
        $type = $this->types->get($closed->type);
        $next = $this->occurrences->currentOfType($closed->type);

        return ClosingMessage::text($closed, $type, null !== $next && $next->id !== $closed->id ? $next : null);
    }

    /**
     * Each event's name, with its year when another of the events has the same name ("Halloween 2026", "Halloween 2027").
     *
     * @param list<EventOccurrence> $events
     *
     * @return array<int,string> by event id
     */
    public static function labels(array $events): array
    {
        $names = array_count_values(array_map(static fn (EventOccurrence $e) => mb_strtolower($e->name), $events));
        $out = [];
        foreach ($events as $e) {
            $out[$e->id] = $names[mb_strtolower($e->name)] > 1 ? ClosingMessage::nameWithYear($e) : $e->name;
        }

        return $out;
    }

    /**
     * The switcher's tabs for a page ('/' or '/me'): shown only when at least two events are public (one event needs no
     * switcher, so a single-event install looks exactly as before).
     *
     * @return list<array{id:int, type:string, label:string, icon:string, state:string, state_words:string, href:string, current:bool}>
     */
    public function tabs(string $path): array
    {
        $events = $this->community->publicEvents();
        if (\count($events) < 2) {
            return [];
        }
        $selected = $this->community->current()->event;
        $labels = self::labels($events);

        return array_map(fn (EventOccurrence $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'label' => $labels[$e->id],
            'icon' => (string) ($this->types->get($e->type)->copy()['event_icon'] ?? ''),
            'state' => $e->state,
            'state_words' => match ($e->state) { EventOccurrence::UPCOMING => 'Soon', EventOccurrence::CLOSED => 'Ended', default => '' },
            'href' => $e->isDefault ? $path : $path.'?e='.$e->id,
            'current' => $e->id === $selected?->id,
        ], $events);
    }
}
