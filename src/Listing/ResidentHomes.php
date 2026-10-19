<?php

declare(strict_types=1);

namespace App\Listing;

use App\Audit\AuditLog;
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
 *     through of($user); organizer remediation lives in Admin/ClaimAdministration.
 *
 * @dependencies DBAL QueryBuilder, AuditLog, clock
 */
final class ResidentHomes
{
    private ?int $ownerId = null;

    public function __construct(
        private readonly Connection $db,
        private readonly AuditLog $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /** A copy bound to one resident; every method below is limited to that resident's claims. */
    public function of(User $user): self
    {
        $scoped = clone $this;
        $scoped->ownerId = $user->id;

        return $scoped;
    }

    /** @return list<array<string,mixed>> the resident's homes with their listing (if any) */
    public function all(): array
    {
        return $this->scoped()
            ->select('a.id', 'a.display_address', 'a.zone_key', 'a.latitude', 'a.longitude', 'a.inside_boundary', 'c.claimed_at', 'l.*')
            ->innerJoin('c', 'eligible_addresses', 'a', 'a.id = c.address_id')
            ->leftJoin('c', 'listings', 'l', 'l.address_id = c.address_id')
            ->orderBy('a.display_address')
            ->executeQuery()->fetchAllAssociative();
    }

    /** @return array<string,mixed>|null the listing row of one of the resident's homes */
    public function find(int $addressId): ?array
    {
        $row = $this->scoped()
            ->select('l.*')
            ->innerJoin('c', 'listings', 'l', 'l.address_id = c.address_id')
            ->andWhere('c.address_id = :address')->setParameter('address', $addressId)
            ->executeQuery()->fetchAssociative();

        return false === $row ? null : $row;
    }

    /** @param array<string,mixed> $draft a normalized ListingDraft */
    public function update(int $addressId, string $eventDate, array $draft): bool
    {
        $owned = $this->scoped()->select('c.address_id');

        return 1 === $this->db->executeStatement(
            'UPDATE listings l SET event_date = :date, start_local = :start, end_local = :end, candy = :candy, nonfood = :nonfood,
                adult_drinks = :adult, low_noise = :low, jump_scares = :jump, flashing_lights = :flash, loud_audio = :loud,
                scare_level = :scare, stroller_friendly = :stroller, note = :note, status = :status, published = :pub,
                published_at = CASE WHEN :pub AND NOT l.published THEN :now ELSE l.published_at END, updated_at = :now
             WHERE l.address_id = :id AND l.address_id IN ('.$owned->getSQL().')',
            ListingRepository::bindings($addressId, $eventDate, $draft) + ['now' => $this->clock->now()->format('Y-m-d H:i:s.uP')] + $owned->getParameters(),
            ListingRepository::bindingTypes(),
        );
    }

    /** Gives up management of one of the resident's homes (its listing cascades away). */
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
}
