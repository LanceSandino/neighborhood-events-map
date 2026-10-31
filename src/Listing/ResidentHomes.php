<?php

declare(strict_types=1);

namespace App\Listing;

use App\Audit\AuditLog;
use App\Event\EventOccurrence;
use App\Security\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Psr\Clock\ClockInterface;

/**
 * @module Listing/ResidentHomes
 * @description Owner-scoped access to a signed-in resident's homes. Every query is built from
 *     scoped() (or scopedClaimsDelete()), which binds the claim owner to the bound user before any
 *     other condition, so a home outside the resident's own claims can never be read, changed or
 *     released, whatever id a caller passes. Resident-facing controllers reach their homes only
 *     through of($user); organizer remediation lives in Admin/ClaimAdministration. Claims are the
 *     resident's for good; listings belong to one event: a home shows its listing for the active event,
 *     and listing a home for a new event starts from an empty form, never from an earlier event's listing.
 *     Writes keep the legacy row in step (Listing/LegacyListingBridge).
 *
 * @dependencies DBAL QueryBuilder, AuditLog, clock, Event/EventOccurrence, Listing/ListingRepository, Listing/LegacyListingBridge
 */
final class ResidentHomes
{
    private ?int $ownerId = null;

    public function __construct(
        private readonly Connection $db,
        private readonly AuditLog $audit,
        private readonly ClockInterface $clock,
        private readonly LegacyListingBridge $bridge,
    ) {
    }

    /** A copy bound to one resident; every method below is limited to that resident's claims. */
    public function of(User $user): self
    {
        $scoped = clone $this;
        $scoped->ownerId = $user->id;

        return $scoped;
    }

    /** @return list<array<string,mixed>> the resident's homes with their listing for the event (if any) */
    public function all(?int $eventId): array
    {
        return $this->scoped()
            ->select('a.id', 'a.display_address', 'a.zone_key', 'a.latitude', 'a.longitude', 'a.inside_boundary', 'c.claimed_at',
                'el.id AS listing_id', 'el.event_id', 'el.details', 'el.status', 'el.status_date', 'el.note', 'el.published', 'el.moderation_hidden',
                'el.moderation_reason', 'el.published_at', 'el.updated_at', ListingRepository::WINDOWS_JSON.' AS windows')
            ->innerJoin('c', 'eligible_addresses', 'a', 'a.id = c.address_id')
            ->leftJoin('c', 'event_listings', 'el', 'el.address_id = c.address_id AND el.event_id = :event')->setParameter('event', $eventId ?? 0)
            ->orderBy('a.display_address')
            ->executeQuery()->fetchAllAssociative();
    }

    /** @return array<string,mixed>|null the event's listing row of one of the resident's homes */
    public function find(int $addressId, int $eventId): ?array
    {
        $row = $this->scoped()
            ->select('el.*', ListingRepository::WINDOWS_JSON.' AS windows')
            ->innerJoin('c', 'event_listings', 'el', 'el.address_id = c.address_id AND el.event_id = :event')->setParameter('event', $eventId)
            ->andWhere('c.address_id = :address')->setParameter('address', $addressId)
            ->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    public function owns(int $addressId): bool
    {
        return false !== $this->scoped()->select('1')->andWhere('c.address_id = :address')->setParameter('address', $addressId)->executeQuery()->fetchOne();
    }

    /**
     * Saves the event's listing of one of the resident's homes.
     *
     * @param array<string,mixed>                              $draft   a normalized ListingDraft
     * @param list<array{date:string,start:string,end:string}> $windows
     */
    public function update(int $addressId, EventOccurrence $event, array $draft, array $windows, ?string $statusDate): bool
    {
        $owned = $this->scoped()->select('c.address_id');

        return $this->db->transactional(function (Connection $db) use ($addressId, $event, $draft, $windows, $statusDate, $owned): bool {
            $id = $db->fetchOne(
                'UPDATE event_listings el SET details = CAST(:details AS JSONB), note = :note, status = :status, status_date = :sdate, published = :pub,
                    published_at = CASE WHEN :pub AND NOT el.published THEN :now ELSE el.published_at END, updated_at = :now
                 WHERE el.event_id = :event AND el.address_id = :id AND el.address_id IN ('.$owned->getSQL().') RETURNING el.id',
                ListingRepository::bindings($addressId, $draft) + ['event' => $event->id, 'sdate' => $statusDate, 'now' => $this->now()] + $owned->getParameters(),
                ['pub' => 'boolean'],
            );
            if (false === $id) {
                return false;
            }
            ListingRepository::replaceWindows($db, (int) $id, $windows);
            $this->bridge->mirror($db, $event->id, $addressId);

            return true;
        });
    }

    /**
     * Lists one of the resident's homes for the event ("List for <event>"): a new listing from the resident's own
     * input. An address under an organizer's hold starts hidden.
     *
     * @param array<string,mixed>                              $draft   a normalized ListingDraft built without any earlier listing
     * @param list<array{date:string,start:string,end:string}> $windows
     *
     * @return 'created'|'exists'|'not_found'
     */
    public function create(int $addressId, EventOccurrence $event, array $draft, array $windows, ?string $statusDate): string
    {
        return $this->db->transactional(function (Connection $db) use ($addressId, $event, $draft, $windows, $statusDate): string {
            $now = $this->now();
            $id = $db->fetchOne(
                'INSERT INTO event_listings (event_id, address_id, details, status, status_date, note, published, moderation_hidden, published_at, updated_at)
                 SELECT CAST(:event AS BIGINT), c.address_id, CAST(:details AS JSONB), CAST(:status AS TEXT), CAST(:sdate AS DATE), CAST(:note AS TEXT),
                    CAST(:pub AS BOOLEAN), a.moderation_hold, CAST(:now AS TIMESTAMPTZ), CAST(:now AS TIMESTAMPTZ)
                 FROM claims c JOIN eligible_addresses a ON a.id = c.address_id WHERE c.user_id = :owner AND c.address_id = :id
                 ON CONFLICT (event_id, address_id) DO NOTHING RETURNING id',
                ListingRepository::bindings($addressId, $draft) + ['event' => $event->id, 'sdate' => $statusDate, 'now' => $now, 'owner' => $this->owner()],
                ['pub' => 'boolean'],
            );
            if (false === $id) {
                return $this->owns($addressId) ? 'exists' : 'not_found';
            }
            ListingRepository::replaceWindows($db, (int) $id, $windows);
            $this->bridge->mirror($db, $event->id, $addressId);

            return 'created';
        });
    }

    /** Gives up management of one of the resident's homes (its listings of every event cascade away). */
    public function release(int $addressId): string
    {
        return $this->db->transactional(function () use ($addressId): string {
            $n = (int) $this->scopedClaimsDelete()->andWhere('address_id = :address')->setParameter('address', $addressId)->executeStatement();
            if (1 !== $n) {
                return 'not_found';
            }
            $this->audit->record($this->owner(), 'user#'.$this->owner(), 'claim.released', 'address', (string) $addressId);

            return 'released';
        });
    }

    private function scoped(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->from('claims', 'c')
            ->where('c.user_id = :owner')->setParameter('owner', $this->owner());
    }

    private function scopedClaimsDelete(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->delete('claims')
            ->where('user_id = :owner')->setParameter('owner', $this->owner());
    }

    private function owner(): int
    {
        return $this->ownerId ?? throw new \LogicException('Call ResidentHomes::of($user) before querying a resident\'s homes.');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.uP');
    }
}
