<?php

declare(strict_types=1);

namespace App\Event;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Event\Type\HalloweenType;
use App\Listing\LegacyListingBridge;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * @module Event/EventLifecycle
 * @description The organizer's events (Organizer → Events, D-20261031-05): prepare a draft of a type this release
 *     offers, edit a draft or the active event, activate a draft (archiving the active one) in one transaction under
 *     the occurrences lock — the caller names the event it replaces, so of two concurrent activations exactly one
 *     wins and the other is refused; the partial unique index is the last line of defense —, archive, and purge an
 *     archived event's listings (retention). Activating never republishes earlier listings: a new event starts empty.
 *     Moving the active event's dates while homes are listed needs the organizer's confirmation; a one-day event's
 *     listings move with its day. Every change is audited and mirrored for the previous release (settings block and
 *     legacy rows).
 *
 * @dependencies DBAL, Event/*, Community/CommunityRepository, Listing/LegacyListingBridge, Audit/AuditLog
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
    ) {
    }

    /** @param array<string,mixed> $input */
    public function createDraft(array $input, int $adminId): EventOccurrence
    {
        $key = \is_string($input['type'] ?? null) ? $input['type'] : '';
        if (!$this->types->isEnabled($key)) {
            throw new ValidationException(['type' => sprintf('Choose one of the event types this version offers: %s.', implode(', ', EventTypeRegistry::ENABLED))]);
        }
        $fields = OccurrenceInput::normalize($input, $this->types->get($key));
        $id = $this->db->transactional(fn (Connection $db) => $this->occurrences->insert($db, $fields, EventOccurrence::DRAFT));
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.created', 'event', (string) $id, ['type' => $fields['type'], 'starts_on' => $fields['starts_on']], 'admin');

        return $this->get($id);
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, int $adminId, bool $moveListings = false): EventOccurrence
    {
        $this->db->transactional(function (Connection $db) use ($id, $input, $moveListings): void {
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
            $listed = $moved ? $this->occurrences->listingCount($id, $db) : 0;
            if ($listed > 0) {
                if (!$moveListings) {
                    throw new EventConflictException('listings_move', sprintf('%d home(s) are listed for this event. Confirm to move them to the new dates, or prepare a new event for the next season.', $listed));
                }
                $this->moveWindows($db, $current, $fields, $type);
            }
            $this->occurrences->updateFields($db, $id, $fields);
            if ($current->isActive()) {
                $updated = $this->occurrences->find($id, $db) ?? throw new \LogicException('The event vanished.');
                $this->community->mirrorEvent($db, $updated);
                if ($moved && $this->isBridged($updated)) {
                    $this->bridge->unpublishDay($db, $current->startsOn);
                    $this->bridge->mirror($db, $id);
                }
            }
        });
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.updated', 'event', (string) $id, ['moved_listings' => $moveListings], 'admin');
        $this->community->reset();

        return $this->get($id);
    }

    /**
     * Activates a draft and archives the event it replaces (null: none is active). Refused when another event became
     * active meanwhile, so two concurrent activations never both win.
     */
    public function activate(int $id, ?int $replaces, int $adminId): EventOccurrence
    {
        try {
            $archived = $this->db->transactional(function (Connection $db) use ($id, $replaces): ?int {
                OccurrenceSettingsSync::lock($db);
                $target = $this->occurrences->find($id, $db, true) ?? throw new EventConflictException('not_found', 'Unknown event.');
                if (EventOccurrence::DRAFT !== $target->state) {
                    throw new EventConflictException('not_draft', 'Only a draft can be activated; this event is '.$target->state.'.');
                }
                if (!$this->types->isEnabled($target->type)) {
                    throw new EventConflictException('type_unavailable', 'This version does not offer that event type.');
                }
                $current = $this->occurrences->active($db);
                if ($current?->id !== $replaces) {
                    throw new EventConflictException('changed', 'Another event became active meanwhile. Reload the page and check before you switch.');
                }
                if (null !== $current) {
                    $this->retire($db, $current);
                }
                $this->occurrences->setState($db, $id, EventOccurrence::ACTIVE);
                $this->community->mirrorEvent($db, $this->occurrences->find($id, $db) ?? throw new \LogicException('The event vanished.'));

                return $current?->id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new EventConflictException('changed', 'Another event became active meanwhile. Reload the page and check before you switch.');
        }
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.activated', 'event', (string) $id, ['archived' => $archived], 'admin');
        $this->community->reset();

        return $this->get($id);
    }

    /** Archives a draft or the active event (any type, including one this version does not offer). */
    public function archive(int $id, int $adminId): EventOccurrence
    {
        $this->db->transactional(function (Connection $db) use ($id): void {
            OccurrenceSettingsSync::lock($db);
            $event = $this->occurrences->find($id, $db, true) ?? throw new EventConflictException('not_found', 'Unknown event.');
            if (EventOccurrence::ARCHIVED === $event->state) {
                throw new EventConflictException('archived', 'This event is already archived.');
            }
            $this->retire($db, $event);
        });
        $this->audit->record($adminId, 'admin#'.$adminId, 'event.archived', 'event', (string) $id, [], 'admin');
        $this->community->reset();

        return $this->get($id);
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
            $active = $this->occurrences->active($db);
            if ($this->isBridgedDay($event) && $active?->startsOn !== $event->startsOn) {
                $this->bridge->deleteDay($db, $event->startsOn);
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

    /** Archives an event inside the caller's transaction; its day leaves the previous release's map too. */
    private function retire(Connection $db, EventOccurrence $event): void
    {
        $this->occurrences->setState($db, $event->id, EventOccurrence::ARCHIVED);
        if ($this->isBridgedDay($event)) {
            $this->bridge->unpublishDay($db, $event->startsOn);
        }
    }

    /**
     * Moves the listings' windows with the event's new dates: a one-day event's to its new day; for other kinds the
     * windows must still fit.
     *
     * @param array{starts_on:string,ends_on:string} $fields
     */
    private function moveWindows(Connection $db, EventOccurrence $current, array $fields, EventType $type): void
    {
        if ('single_day' === $type->scheduleKind()) {
            $db->executeStatement('UPDATE event_listing_windows w SET local_date = :day FROM event_listings el WHERE el.id = w.listing_id AND el.event_id = :event',
                ['day' => $fields['starts_on'], 'event' => $current->id]);

            return;
        }
        $outside = (int) $db->fetchOne('SELECT COUNT(*) FROM event_listing_windows w JOIN event_listings el ON el.id = w.listing_id
            WHERE el.event_id = :event AND (w.local_date < :from OR w.local_date > :to)', ['event' => $current->id, 'from' => $fields['starts_on'], 'to' => $fields['ends_on']]);
        if ($outside > 0) {
            throw new EventConflictException('listings_outside', sprintf('%d listing day(s) fall outside the new dates. Keep the dates that homes chose.', $outside));
        }
    }

    /** The legacy table mirrors this event (active, Halloween, one day). */
    private function isBridged(EventOccurrence $e): bool
    {
        return $e->isActive() && $this->isBridgedDay($e);
    }

    private function isBridgedDay(EventOccurrence $e): bool
    {
        return HalloweenType::KEY === $e->type && $e->startsOn === $e->endsOn;
    }
}
