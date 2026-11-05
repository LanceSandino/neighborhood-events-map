<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunityRepository;

/**
 * @module Event/PublicEvents
 * @description The events visitors may see (upcoming, open, closed; of types this release offers), the default first:
 *     their public description for GET /api/events — id, type, name, state, dates, tentative, default and, for a
 *     closed event, its thank-you text —, the event switcher's tabs (emoji and name, the year when two events
 *     share a name, the state in words for an event that is not open, the link and whether it is the selected one) and
 *     the list of past events. The map's tabs are the current events (open and upcoming); closed events are listed under
 *     Past events, newest first (D-20261104-02). Drafts, hidden and archived events are never described to visitors (ADR
 *     0011, D-20261031-40); My house's switcher keeps closed events as read-only tabs and also has a tab for a hidden event
 *     residents get ready for ("Getting ready", D-20261101-01). A closed event's thank-you text and card name the next
 *     event of its type only once it is public, and lead back to the current default event.
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

    /** The thank-you text of a closed event (the organizer's, or the type's default naming next year's dates once announced). */
    public function closingMessage(EventOccurrence $closed): string
    {
        return ClosingMessage::text($closed, $this->types->get($closed->type), $this->publicNext($closed));
    }

    /**
     * The event of the same type that follows a closed one, for its thank-you card ("See <event>"): only while it is
     * public (upcoming or open); a hidden one is never named.
     *
     * @param array<string,mixed> $closed the public event payload (c.event) or the event
     *
     * @return array{id:int, label:string}|null
     */
    public function nextAfter(array|EventOccurrence $closed): ?array
    {
        $event = $closed instanceof EventOccurrence ? $closed : $this->occurrences->find((int) ($closed['id'] ?? 0));
        $next = null === $event || EventOccurrence::CLOSED !== $event->state ? null : $this->publicNext($event);
        if (null === $next) {
            return null;
        }
        $labels = self::labels($this->community->publicEvents()); // the next event is one of them

        return ['id' => $next->id, 'label' => $labels[$next->id] ?? $next->name];
    }

    /**
     * The way back from a closed event's thank-you page: the default event while it is another one and current.
     *
     * @param array<string,mixed>|EventOccurrence $closed the public event payload (c.event) or the event
     *
     * @return array{id:int, label:string}|null
     */
    public function wayBack(array|EventOccurrence $closed): ?array
    {
        $id = $closed instanceof EventOccurrence ? $closed->id : (int) ($closed['id'] ?? 0);
        $events = $this->community->publicEvents();
        $labels = self::labels($events);
        foreach ($events as $e) {
            if ($e->isDefault) {
                return $e->id !== $id && $e->isOnSiteNow() ? ['id' => $e->id, 'label' => $labels[$e->id]] : null;
            }
        }

        return null;
    }

    /**
     * The past events visitors can open: the closed ones, newest first, each with its year and dates.
     *
     * @return list<array{id:int, label:string, icon:string, dates:string, href:string}>
     */
    public function past(): array
    {
        $closed = array_filter($this->community->publicEvents(), static fn (EventOccurrence $e) => EventOccurrence::CLOSED === $e->state);
        usort($closed, static fn (EventOccurrence $a, EventOccurrence $b) => [$b->startsOn ?? '', $b->id] <=> [$a->startsOn ?? '', $a->id]);

        return array_map(fn (EventOccurrence $e) => [
            'id' => $e->id,
            'label' => ClosingMessage::nameWithYear($e),
            'icon' => (string) ($this->types->get($e->type)->copy()['event_icon'] ?? ''),
            'dates' => EventDates::words($e),
            'href' => '/?e='.$e->id,
        ], $closed);
    }

    /** The current event of a closed one's type while it is public and of a type this release offers. */
    private function publicNext(EventOccurrence $closed): ?EventOccurrence
    {
        $next = $this->occurrences->currentOfType($closed->type);

        return null !== $next && $next->id !== $closed->id && $next->isPublic() && $this->types->isEnabled($next->type) ? $next : null;
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
     * The switcher's tabs for a page ('/' or '/me'): the map's are the current events and show only when at least two are
     * current (one event needs no switcher, so a single-event install looks exactly as before). My house (signed-in
     * residents only) counts and shows its closed and hidden events too.
     *
     * @return list<array{id:int, type:string, label:string, icon:string, state:string, state_words:string, href:string, current:bool}>
     */
    public function tabs(string $path): array
    {
        $events = '/me' === $path ? $this->community->residentEvents()
            : array_values(array_filter($this->community->publicEvents(), static fn (EventOccurrence $e) => $e->isOnSiteNow()));
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
            'state_words' => match ($e->state) { EventOccurrence::UPCOMING => 'Soon', EventOccurrence::CLOSED => 'Ended', EventOccurrence::HIDDEN => 'Getting ready', default => '' },
            'href' => $e->isDefault ? $path : $path.'?e='.$e->id,
            'current' => $e->id === $selected?->id,
        ], $events);
    }
}
