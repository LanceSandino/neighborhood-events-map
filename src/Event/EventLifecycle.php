<?php

declare(strict_types=1);

namespace App\Event;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Community\LogoStore;
use App\Listing\LegacyListingBridge;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * @module Event/EventLifecycle
 * @description The organizer's events (Organizer → Events, ADR 0011): prepare a draft (or a hidden event) of a type this
 *     release offers, edit an event, hide a draft or an upcoming event (residents get ready in My house, visitors see
 *     nothing; D-20261101-01), announce a draft or a hidden event (upcoming: residents may list, visitors see its dates),
 *     open an event (the public map with its homes; its dates are then confirmed), close an open event, by hand or by
 *     itself after its last day (D-20261104-01) — in the same transaction next year's event of the type is created hidden
 *     with tentative dates (or dates to be announced, Event/NextEvent) —, make an upcoming or open event the default,
 *     archive and purge an archived event's listings (retention). A default that closes, is archived or is hidden passes
 *     on by Event/DefaultEventRule (D-20261104-03). At most one hidden, upcoming or open event per type, one default, a
 *     closed event never reopens (D-20261031-37, -39). Every change runs in one transaction under the occurrences lock
 *     (the partial unique indexes are the last line of defense), with its audit row, and is mirrored for the previous
 *     release (settings block and legacy rows of the bridged Halloween, D-20261031-41). Nothing is copied from an earlier
 *     event's listings.
 *
 * @dependencies DBAL, Event/* (Event/NextEvent: next year's event; Event/DefaultEventRule: where the default goes; Event/EventDateChange: new dates and listed days), Community/CommunityRepository, Community/LogoStore, Listing/LegacyListingBridge, Audit/AuditLog
 */
final class EventLifecycle
{
    public function __construct(
        private readonly Connection $db,
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventTypeRegistry $types,
        private readonly LegacyListingBridge $bridge,
        private readonly CommunityRepository $community,
        private readonly AuditLog $audit,
        private readonly NextEvent $next,
        private readonly DefaultEventRule $defaults,
        private readonly EventDateChange $dateChange,
        private readonly LogoStore $logos,
    ) {
    }

    /**
     * A new event: a draft (organizers only), or hidden (residents may get ready at once; the type must have no current
     * event, checked under the occurrences lock).
     *
     * @param array<string,mixed> $input
     */
    public function createDraft(array $input, int $adminId, string $state = EventOccurrence::DRAFT): EventOccurrence
    {
        $key = \is_string($input['type'] ?? null) ? $input['type'] : '';
        if (!$this->types->isEnabled($key)) {
            throw new ValidationException(['type' => sprintf('Choose one of the event types this version offers: %s.', implode(', ', array_map(static fn (EventType $t) => $t->key(), $this->types->enabled())))]);
        }
        if (!\in_array($state, [EventOccurrence::DRAFT, EventOccurrence::HIDDEN], true)) {
            throw new ValidationException(['state' => 'A new event starts as a draft or hidden (residents can get ready).']);
        }
        $fields = OccurrenceInput::normalize($input, $this->types->get($key));
        try {
            $id = $this->db->transactional(function (Connection $db) use ($fields, $state): int {
                if (EventOccurrence::HIDDEN === $state) {
                    OccurrenceSettingsSync::lock($db);
                    $this->assertNoOtherCurrent($db, new EventOccurrence(0, $fields['type'], $fields['name'], null, null, '', '', [], $state));
                }

                return $this->occurrences->insert($db, $fields, $state);
            });
        } catch (UniqueConstraintViolationException) {
            throw new EventConflictException('changed', 'Another change to the events happened meanwhile. Reload the page and check before you try again.');
        }
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.created', 'event', (string) $id, ['type' => $fields['type'], 'starts_on' => $fields['starts_on'], 'state' => $state], 'admin');

        return $this->get($id);
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, int $adminId, bool $moveListings = false): EventOccurrence
    {
        $this->db->transactional(function (Connection $db) use ($id, $input, $moveListings, $adminId): void {
            OccurrenceSettingsSync::lock($db);
            $current = $this->occurrences->find($id, $db, true) ?? throw new EventConflictException('not_found', 'Unknown event.');
            if (EventOccurrence::ARCHIVED === $current->state) {
                throw new EventConflictException('archived', 'An archived event cannot change.');
            }
            if (!$this->types->isEnabled($current->type)) {
                throw new EventConflictException('type_unavailable', 'This version does not offer that event type; you can only archive this event.');
            }
            $type = $this->types->get($current->type);
            $fields = OccurrenceInput::normalize($input, $type, $current);
            $moved = $fields['starts_on'] !== $current->startsOn || $fields['ends_on'] !== $current->endsOn;
            if (EventOccurrence::CLOSED === $current->state && ($moved || $fields['default_start'] !== $current->defaultStart || $fields['default_end'] !== $current->defaultEnd)) {
                throw new EventConflictException('closed', 'A closed event keeps its dates and hours; you can change its name and thank-you message.');
            }
            if (EventOccurrence::OPEN === $current->state && null === $fields['starts_on']) {
                throw new EventConflictException('dates_needed', 'An open event keeps its dates. Close it when it is over.');
            }
            $listed = $moved ? $this->occurrences->listingCount($id, $db) : 0;
            if ($listed > 0) {
                if (!$moveListings || null === $fields['starts_on']) {
                    throw new EventConflictException('listings_move', sprintf('%d home(s) are listed for this event. Confirm to move them to the new dates, or prepare a new event for the next season.', $listed));
                }
                $this->dateChange->moveWindows($db, $current, $fields, $type);
            }
            $this->dateChange->keepListedDays($db, $current, $fields, $type);
            $this->occurrences->updateFields($db, $id, $fields);
            $updated = $this->occurrences->find($id, $db) ?? throw new \LogicException('The event vanished.');
            if ($moved && $this->isBridgedDay($current)) {
                $this->bridge->unpublishDay($db, (string) $current->startsOn);
            }
            $this->settle($db, $updated, $adminId, $current->isDefault ? $current->id : null);
        });
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.updated', 'event', (string) $id, ['moved_listings' => $moveListings], 'admin');
        $this->community->reset();

        return $this->get($id);
    }

    /** A draft or a hidden event becomes upcoming: residents may list (once it has dates) and visitors see it with its dates. */
    public function announce(int $id, ?int $adminId): EventOccurrence
    {
        return $this->change($id, $adminId, 'event.announced', function (Connection $db, EventOccurrence $e): array {
            if (!\in_array($e->state, [EventOccurrence::DRAFT, EventOccurrence::HIDDEN], true)) {
                throw new EventConflictException('not_announceable', 'Only a draft or a hidden event can be announced; this event is '.$e->state.'.');
            }
            $this->assertNoOtherCurrent($db, $e);
            $this->occurrences->setState($db, $e->id, EventOccurrence::UPCOMING);

            return [];
        });
    }

    /**
     * A draft or an upcoming event becomes hidden: visitors no longer see it (no tab, no page, no API), residents keep
     * getting ready in My house and their listings stay; a default passes on.
     */
    public function hide(int $id, ?int $adminId): EventOccurrence
    {
        return $this->change($id, $adminId, 'event.hidden', function (Connection $db, EventOccurrence $e): array {
            if (!\in_array($e->state, [EventOccurrence::DRAFT, EventOccurrence::UPCOMING], true)) {
                throw new EventConflictException('not_hideable', EventOccurrence::OPEN === $e->state
                    ? 'An open event is on the map; close it when it is over.'
                    : 'Only a draft or an upcoming event can be hidden; this event is '.$e->state.'.');
            }
            $this->assertNoOtherCurrent($db, $e);
            $this->occurrences->setState($db, $e->id, EventOccurrence::HIDDEN);

            return ['was_default' => $e->isDefault];
        });
    }

    /** A draft, a hidden or an upcoming event opens: the public map shows its homes and its dates are confirmed. */
    public function open(int $id, ?int $adminId): EventOccurrence
    {
        return $this->change($id, $adminId, 'event.opened', function (Connection $db, EventOccurrence $e): array {
            if (!\in_array($e->state, [EventOccurrence::DRAFT, EventOccurrence::HIDDEN, EventOccurrence::UPCOMING], true)) {
                throw new EventConflictException('not_openable', EventOccurrence::CLOSED === $e->state
                    ? 'A closed event stays closed. Next year\'s event was created when it closed; open that one.'
                    : 'Only a draft, a hidden or an upcoming event can be opened; this event is '.$e->state.'.');
            }
            if (!$e->hasDates()) {
                throw new EventConflictException('dates_tbd', 'Set the event\'s dates before you open it.');
            }
            $type = $this->types->get($e->type);
            if ($type instanceof EventSettingsType && [] === ($type->listingDates($e) ?? $e->dates())) {
                throw new EventConflictException('no_days', 'Choose the event\'s days (for a sale: one to three sale days) before you open it.');
            }
            $this->assertNoOtherCurrent($db, $e);
            $this->occurrences->setState($db, $e->id, EventOccurrence::OPEN);

            return [];
        });
    }

    /**
     * Closes an open event and creates next year's event of the type (hidden: residents get ready, visitors see
     * nothing until it is announced; tentative dates or dates to be announced) in the same transaction. The thank-you text may be set at the same time.
     *
     * @return array{closed: EventOccurrence, next: EventOccurrence}
     */
    public function close(int $id, ?string $message, ?int $adminId): array
    {
        return $this->closing($id, $message, $adminId, null);
    }

    /**
     * Closes an open event whose last day is before a local date, exactly as the organizer's Close does (D-20261104-01):
     * the state and the date are checked under the lock, so an organizer's Close or new dates that came first win.
     *
     * @return array{closed: EventOccurrence, next: EventOccurrence}|null null: it is not open any more, or not over
     */
    public function closeEnded(int $id, string $today): ?array
    {
        try {
            return $this->closing($id, null, null, $today);
        } catch (EventConflictException $e) {
            if (\in_array($e->reason, ['not_open', 'not_ended', 'not_found'], true)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * A closed default moves on as soon as a public current event exists (a default an earlier release left on a closed
     * event; Event/EndedEvents runs this). Takes the lock only when there is something to do.
     */
    public function settleDefault(): void
    {
        $flagged = $this->occurrences->flaggedDefault();
        if ((null !== $flagged && EventOccurrence::CLOSED !== $flagged->state) || null === $this->defaults->soonest($this->db)) {
            return;
        }
        $this->db->transactional(function (Connection $db): void {
            OccurrenceSettingsSync::lock($db);
            if ($this->defaults->settle($db, null)) {
                $this->community->mirrorLegacy($db);
            }
        });
        $this->community->reset();
    }

    /** @return array{closed: EventOccurrence, next: EventOccurrence} */
    private function closing(int $id, ?string $message, ?int $adminId, ?string $endedBefore): array
    {
        $nextId = 0;
        $closed = $this->change($id, $adminId, 'event.closed', function (Connection $db, EventOccurrence $e) use ($message, $endedBefore, &$nextId): array {
            if (EventOccurrence::OPEN !== $e->state) {
                throw new EventConflictException('not_open', 'Only an open event can be closed; this event is '.$e->state.'.');
            }
            if (null !== $endedBefore && (!$e->hasDates() || $e->lastDay() >= $endedBefore)) {
                throw new EventConflictException('not_ended', 'This event is not over yet.');
            }
            $type = $this->types->get($e->type);
            $settings = $e->settings;
            if (null !== $message) {
                $settings = OccurrenceInput::normalize(['settings' => ['closing_message' => $message] + $settings], $type, $e)['settings'];
                $this->occurrences->updateFields($db, $e->id, ['name' => $e->name, 'starts_on' => $e->startsOn, 'ends_on' => $e->endsOn,
                    'default_start' => $e->defaultStart, 'default_end' => $e->defaultEnd, 'settings' => $settings]);
            }
            $this->occurrences->setState($db, $e->id, EventOccurrence::CLOSED);
            $nextId = $this->occurrences->insert($db, $this->next->fields($e), EventOccurrence::HIDDEN);
            $this->logos->copy($e->id, $nextId); // next year's event keeps the event's logo, like its branding

            return ['next_id' => $nextId] + (null !== $endedBefore ? ['automatic' => true, 'last_day' => $e->lastDay()] : []);
        });

        return ['closed' => $closed, 'next' => $this->get($nextId)];
    }

    /**
     * Next year's event of a closed one, created again (hidden, as closing creates it) when its type has no current
     * event (the fictional demo's seed after an organizer archived it); null when one exists.
     */
    public function ensureNext(EventOccurrence $closed, ?int $adminId): ?EventOccurrence
    {
        if (EventOccurrence::CLOSED !== $closed->state || null !== $this->occurrences->currentOfType($closed->type)) {
            return null;
        }
        $fields = $this->next->fields($closed);
        $id = $this->db->transactional(function (Connection $db) use ($fields): int {
            OccurrenceSettingsSync::lock($db);

            return $this->occurrences->insert($db, $fields, EventOccurrence::HIDDEN);
        });
        $this->audit->record($adminId, null === $adminId ? 'cli' : 'admin#'.$adminId, 'event.created', 'event', (string) $id, ['type' => $fields['type'], 'starts_on' => $fields['starts_on'], 'after' => $closed->id], null === $adminId ? 'cli' : 'admin');
        $this->community->reset();

        return $this->get($id);
    }

    /**
     * What closing an open event will do, for the organizer's confirmation (Event/NextEvent) and, when it is the default,
     * which event "/" shows afterwards (null: it stays on its thank-you page, or the event is not the default).
     *
     * @return array{message: string, next: array{name: string, starts_on: ?string, ends_on: ?string, dates_tentative: bool, words: string, state: string}, default_after: ?string}
     */
    public function closePreview(EventOccurrence $e): array
    {
        return $this->next->preview($e) + ['default_after' => $e->isDefault ? $this->defaults->soonest($this->db, $e->id)?->name : null];
    }

    /**
     * An upcoming or open event becomes the default: what "/" shows. A closed event cannot be chosen: visitors find it
     * under Past events, and "/" stays on one only while nothing is current (D-20261104-04).
     */
    public function makeDefault(int $id, ?int $adminId): EventOccurrence
    {
        return $this->change($id, $adminId, 'event.default', function (Connection $db, EventOccurrence $e): array {
            if (EventOccurrence::CLOSED === $e->state) {
                throw new EventConflictException('not_current', 'A closed event cannot be the default: visitors find it under Past events, and "/" shows the current event happening soonest.');
            }
            if (!$e->isPublic()) {
                throw new EventConflictException('not_public', 'Only an upcoming, open or closed event can be the default; this event is '.$e->state.'.');
            }
            $this->occurrences->setDefault($db, $e->id);

            return [];
        });
    }

    /** Archives any event that is not archived yet (including one of a type this version does not offer). */
    public function archive(int $id, ?int $adminId): EventOccurrence
    {
        return $this->change($id, $adminId, 'event.archived', function (Connection $db, EventOccurrence $e): array {
            if (EventOccurrence::ARCHIVED === $e->state) {
                throw new EventConflictException('archived', 'This event is already archived.');
            }
            $this->occurrences->setState($db, $e->id, EventOccurrence::ARCHIVED);
            if ($this->isBridgedDay($e)) {
                $this->bridge->unpublishDay($db, (string) $e->startsOn); // its day leaves the previous release's map
            }

            return ['was_default' => $e->isDefault];
        }, offeredOnly: false);
    }

    /** Retention: deletes an archived event's listings (and their legacy rows); claims stay. */
    public function purgeListings(int $id, int $adminId): int
    {
        $n = $this->db->transactional(function (Connection $db) use ($id): int {
            OccurrenceSettingsSync::lock($db);
            $event = $this->occurrences->find($id, $db, true) ?? throw new EventConflictException('not_found', 'Unknown event.');
            if (EventOccurrence::ARCHIVED !== $event->state) {
                throw new EventConflictException('not_archived', 'Archive the event before deleting its listings.');
            }
            $n = $db->executeStatement('DELETE FROM event_listings WHERE event_id = ?', [$id]);
            if ($this->isBridgedDay($event) && $this->occurrences->bridged($db)?->startsOn !== $event->startsOn) {
                $this->bridge->deleteDay($db, (string) $event->startsOn);
            }

            return $n;
        });
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.listings_purged', 'event', (string) $id, ['count' => $n], 'admin');

        return $n;
    }

    public function get(int $id): EventOccurrence
    {
        return $this->occurrences->find($id) ?? throw new EventConflictException('not_found', 'Unknown event.');
    }

    /**
     * Runs one life-cycle change in one transaction under the occurrences lock: the event is locked, the change applied
     * and audited, the default settled and the previous release's mirror brought up to date; a unique index that still
     * refuses (a second current event of a type, a second default) answers 409.
     *
     * @param callable(Connection, EventOccurrence): array<string,mixed> $apply returns the audit details
     */
    private function change(int $id, ?int $adminId, string $action, callable $apply, bool $offeredOnly = true): EventOccurrence
    {
        try {
            $this->db->transactional(function (Connection $db) use ($id, $adminId, $action, $apply, $offeredOnly): void {
                OccurrenceSettingsSync::lock($db);
                $event = $this->occurrences->find($id, $db, true) ?? throw new EventConflictException('not_found', 'Unknown event.');
                if ($offeredOnly && !$this->types->isEnabled($event->type)) {
                    throw new EventConflictException('type_unavailable', 'This version does not offer that event type; you can only archive this event.');
                }
                $details = $apply($db, $event);
                // A command-line step (the automatic close, the fictional demo's seed) has no organizer account.
                $this->audit->record($adminId, null === $adminId ? 'cli' : 'admin#'.$adminId, $action, 'event', (string) $id, $details, null === $adminId ? 'cli' : 'admin');
                $this->settle($db, $this->occurrences->find($id, $db) ?? throw new \LogicException('The event vanished.'), $adminId, $event->isDefault ? $event->id : null);
            });
        } catch (UniqueConstraintViolationException) {
            throw new EventConflictException('changed', 'Another change to the events happened meanwhile. Reload the page and check before you try again.');
        }
        $this->community->reset();

        return $this->get($id);
    }

    /**
     * After a change: the default follows Event/DefaultEventRule, the bridged Halloween's legacy rows and the settings
     * block follow.
     *
     * @param int|null $wasDefault the changed event, when it was the default before the change
     */
    private function settle(Connection $db, EventOccurrence $changed, ?int $adminId, ?int $wasDefault): void
    {
        $this->defaults->settle($db, $adminId, $wasDefault);
        $bridged = $this->occurrences->bridged($db);
        if (null !== $bridged && ($this->isBridgedDay($changed) || $bridged->id === $changed->id)) {
            $this->bridge->mirror($db, $bridged->id);
        }
        $this->community->mirrorLegacy($db);
    }

    private function assertNoOtherCurrent(Connection $db, EventOccurrence $e): void
    {
        $current = $this->occurrences->currentOfType($e->type, $db);
        if (null !== $current && $current->id !== $e->id) {
            throw new EventConflictException('type_current', sprintf('“%s” is already the current event of this kind (%s). Close or archive it first; closing creates next year\'s event.', $current->name, $current->state));
        }
    }

    /** A one-day Halloween event: the kind the legacy table can mirror. */
    private function isBridgedDay(EventOccurrence $e): bool
    {
        return Type\HalloweenType::KEY === $e->type && null !== $e->startsOn && $e->startsOn === $e->endsOn;
    }
}
