<?php

declare(strict_types=1);

namespace App\Listing;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Listing/ListingRepository
 * @description Listing persistence plus the PUBLIC projection used by the family map. The public
 *     queries return only opted-in, unmoderated, in-boundary listings for the current event date and
 *     never select owner/user columns. Resident-scoped reads and writes live in ResidentHomes.
 *
 * @dependencies DBAL, clock
 */
final class ListingRepository
{
    private const PUBLIC_COLUMNS = 'a.id, a.display_address, a.zone_key, a.latitude, a.longitude,
        l.start_local, l.end_local, l.candy, l.nonfood, l.adult_drinks, l.low_noise, l.jump_scares, l.flashing_lights,
        l.loud_audio, l.scare_level, l.stroller_friendly, l.note, l.status, l.updated_at';
    /** moderation_reason of a listing whose new (self-entered) address waits for an organizer's approval */
    public const PENDING_REVIEW = 'pending_review';

    private const PUBLIC_WHERE = 'l.published AND NOT l.moderation_hidden AND a.enabled AND a.inside_boundary AND l.event_date = :date';

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    /** @param array<string,mixed> $draft */
    public function insert(Connection $db, int $addressId, string $eventDate, array $draft): void
    {
        $now = $this->now();
        $db->executeStatement(
            'INSERT INTO listings (address_id, event_date, start_local, end_local, candy, nonfood, adult_drinks, low_noise, jump_scares,
                flashing_lights, loud_audio, scare_level, stroller_friendly, note, status, published, published_at, updated_at, moderation_hidden)
             VALUES (:id, :date, :start, :end, :candy, :nonfood, :adult, :low, :jump, :flash, :loud, :scare, :stroller, :note, :status, :pub, :now, :now,
                (SELECT moderation_hold FROM eligible_addresses WHERE id = :id))
             ON CONFLICT (address_id) DO UPDATE SET event_date = EXCLUDED.event_date, start_local = EXCLUDED.start_local, end_local = EXCLUDED.end_local,
                candy = EXCLUDED.candy, nonfood = EXCLUDED.nonfood, adult_drinks = EXCLUDED.adult_drinks, low_noise = EXCLUDED.low_noise,
                jump_scares = EXCLUDED.jump_scares, flashing_lights = EXCLUDED.flashing_lights, loud_audio = EXCLUDED.loud_audio,
                scare_level = EXCLUDED.scare_level, stroller_friendly = EXCLUDED.stroller_friendly, note = EXCLUDED.note,
                status = EXCLUDED.status, published = EXCLUDED.published, moderation_hidden = listings.moderation_hidden OR EXCLUDED.moderation_hidden,
                published_at = EXCLUDED.published_at,
                updated_at = EXCLUDED.updated_at',
            self::bindings($addressId, $eventDate, $draft) + ['now' => $now],
            self::bindingTypes(),
        );
    }

    /**
     * @param list<string> $hiddenZones zones the organizer has hidden: their homes are not public either
     *
     * @return list<array<string,mixed>>
     */
    public function publicHomes(string $eventDate, array $hiddenZones = []): array
    {
        $homes = array_map([self::class, 'publicShape'], $this->db->fetchAllAssociative(
            'SELECT '.self::PUBLIC_COLUMNS.' FROM listings l JOIN eligible_addresses a ON a.id = l.address_id
             WHERE '.self::PUBLIC_WHERE.' ORDER BY a.street, a.house_number',
            ['date' => $eventDate],
        ));

        return array_values(array_filter($homes, static fn (array $h) => !\in_array($h['zone'], $hiddenZones, true)));
    }

    /**
     * One public home by id: the same rules as publicHomes(), hidden zones included.
     *
     * @param list<string> $hiddenZones zones the organizer has hidden: their homes are not public either
     *
     * @return array<string,mixed>|null
     */
    public function publicHome(int $addressId, string $eventDate, array $hiddenZones): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT '.self::PUBLIC_COLUMNS.' FROM listings l JOIN eligible_addresses a ON a.id = l.address_id
             WHERE '.self::PUBLIC_WHERE.' AND a.id = :id',
            ['date' => $eventDate, 'id' => $addressId],
        );
        if (false === $row || \in_array($row['zone_key'], $hiddenZones, true)) {
            return null;
        }

        return self::publicShape($row);
    }

    /**
     * Hides/unhides a listing. The decision is also kept on the address (moderation_hold), so a new
     * listing for the same address — after release and re-claim — starts hidden until unhidden.
     */
    public function setModeration(int $addressId, bool $hidden, ?string $reason): bool
    {
        return $this->db->transactional(function (Connection $db) use ($addressId, $hidden, $reason): bool {
            $n = $db->executeStatement(
                'UPDATE listings SET moderation_hidden = :h, moderation_reason = :r, updated_at = :now WHERE address_id = :id',
                ['h' => $hidden, 'r' => $hidden ? $reason : null, 'now' => $this->now(), 'id' => $addressId],
                ['h' => 'boolean'],
            );
            if (1 === $n) {
                // Unhiding is also the approval of a self-entered address that was waiting for review.
                $db->executeStatement('UPDATE eligible_addresses SET moderation_hold = :h, reviewed_at = CASE WHEN :h THEN reviewed_at ELSE COALESCE(reviewed_at, :now) END WHERE id = :id',
                    ['h' => $hidden, 'now' => $this->now(), 'id' => $addressId], ['h' => 'boolean']);
            }

            return 1 === $n;
        });
    }

    /** Hides every listing from a past event date (season archive). */
    public function archiveBefore(string $eventDate): int
    {
        return $this->db->executeStatement('UPDATE listings SET published = FALSE, updated_at = :now WHERE event_date < :d AND published', ['now' => $this->now(), 'd' => $eventDate]);
    }

    /** @param array<string,mixed> $r */
    public static function publicShape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'address' => (string) $r['display_address'],
            'zone' => $r['zone_key'],
            'lat' => round((float) $r['latitude'], 7),
            'lon' => round((float) $r['longitude'], 7),
            'start' => substr((string) $r['start_local'], 0, 5),
            'end' => substr((string) $r['end_local'], 0, 5),
            'candy' => (bool) $r['candy'],
            'nonfood' => (bool) $r['nonfood'],
            'adult_drinks' => (bool) $r['adult_drinks'],
            'low_noise' => (bool) $r['low_noise'],
            'jump_scares' => (bool) $r['jump_scares'],
            'flashing_lights' => (bool) $r['flashing_lights'],
            'loud_audio' => (bool) $r['loud_audio'],
            'scare_level' => (string) $r['scare_level'],
            'stroller_friendly' => (bool) $r['stroller_friendly'],
            'note' => (string) $r['note'],
            'status' => (string) $r['status'],
            'updated_at' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $row listing row → draft array */
    public static function toDraft(array $row): array
    {
        return [
            'start' => substr((string) $row['start_local'], 0, 5), 'end' => substr((string) $row['end_local'], 0, 5),
            'candy' => (bool) $row['candy'], 'nonfood' => (bool) $row['nonfood'], 'adult_drinks' => (bool) $row['adult_drinks'],
            'low_noise' => (bool) $row['low_noise'], 'jump_scares' => (bool) $row['jump_scares'], 'flashing_lights' => (bool) $row['flashing_lights'],
            'loud_audio' => (bool) $row['loud_audio'], 'stroller_friendly' => (bool) $row['stroller_friendly'],
            'scare_level' => (string) $row['scare_level'], 'note' => (string) $row['note'], 'status' => (string) $row['status'],
            'published' => (bool) $row['published'],
        ];
    }

    /** @param array<string,mixed> $d normalized draft → named SQL parameters shared by every listing write */
    public static function bindings(int $addressId, string $eventDate, array $d): array
    {
        return ['id' => $addressId, 'date' => $eventDate, 'start' => $d['start'], 'end' => $d['end'], 'candy' => $d['candy'],
            'nonfood' => $d['nonfood'], 'adult' => $d['adult_drinks'], 'low' => $d['low_noise'], 'jump' => $d['jump_scares'],
            'flash' => $d['flashing_lights'], 'loud' => $d['loud_audio'], 'scare' => $d['scare_level'], 'stroller' => $d['stroller_friendly'],
            'note' => $d['note'], 'status' => $d['status'], 'pub' => $d['published']];
    }

    /** @return array<string,string> */
    public static function bindingTypes(): array
    {
        return array_fill_keys(['candy', 'nonfood', 'adult', 'low', 'jump', 'flash', 'loud', 'stroller', 'pub'], 'boolean');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.uP');
    }
}
