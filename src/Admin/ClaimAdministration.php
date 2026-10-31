<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Listing\ListingRepository;
use App\Security\EmailNormalizer;
use App\Security\UserRepository;
use App\Shared\TextInput;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Admin/ClaimAdministration
 * @description Organizer-only manual remediation: transfer a claim to another email, unclaim,
 *     hide/unhide a listing, export claims. Every action requires explicit confirmation from the
 *     caller and writes an audit event with old/new account ids. No automated adjudication. Claims are
 *     address-scoped; hide/unhide and the export's listing columns concern the active event's listing (the hold
 *     itself stays on the address).
 *
 * @dependencies DBAL, clock, Security/*, Listing/ListingRepository, Community/CommunityRepository, Audit/AuditLog
 */
final class ClaimAdministration
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly UserRepository $users,
        private readonly EmailNormalizer $emails,
        private readonly ListingRepository $listings,
        private readonly AuditLog $audit,
        private readonly CommunityRepository $community,
    ) {
    }

    /** @return array{from_user_id:int, to_user_id:int} */
    public function transfer(int $addressId, string $email, int $adminId, ?int $disputeId = null): array
    {
        $to = $this->emails->normalize($email) ?? throw new ValidationException(['email' => 'Enter a valid email address.']);
        $target = $this->users->ensure($to);

        if (null !== $disputeId && false === $this->db->fetchOne('SELECT 1 FROM disputes WHERE id = ? AND address_id = ?', [$disputeId, $addressId])) {
            throw new ValidationException(['dispute_id' => 'That review request is not about this home.']);
        }

        return $this->db->transactional(function (Connection $db) use ($addressId, $target, $adminId, $disputeId): array {
            $from = $db->fetchOne('SELECT user_id FROM claims WHERE address_id = ? FOR UPDATE', [$addressId]);
            if (false === $from) {
                throw new ValidationException(['address_id' => 'That address is not claimed.']);
            }
            $db->executeStatement('UPDATE claims SET user_id = ?, updated_at = ? WHERE address_id = ?', [$target->id, $this->now(), $addressId]);
            $this->audit->record($adminId, 'admin#'.$adminId, 'claim.transferred', 'address', (string) $addressId,
                ['from_user_id' => (int) $from, 'to_user_id' => $target->id, 'dispute_id' => $disputeId], 'admin');

            return ['from_user_id' => (int) $from, 'to_user_id' => $target->id];
        });
    }

    public function unclaim(int $addressId, int $adminId, string $reason): int
    {
        return $this->db->transactional(function (Connection $db) use ($addressId, $adminId, $reason): int {
            $from = $db->fetchOne('DELETE FROM claims WHERE address_id = ? RETURNING user_id', [$addressId]);
            if (false === $from) {
                throw new ValidationException(['address_id' => 'That address is not claimed.']);
            }
            $this->audit->record($adminId, 'admin#'.$adminId, 'claim.unclaimed', 'address', (string) $addressId,
                ['from_user_id' => (int) $from, 'reason' => TextInput::line($reason, 300)], 'admin');

            return (int) $from;
        });
    }

    public function moderate(int $addressId, bool $hide, string $reason, int $adminId): void
    {
        $reason = TextInput::line($reason, 300);
        $event = $this->community->current()->event;
        if (null === $event || !$this->listings->setModeration($event->id, $addressId, $hide, '' !== $reason ? $reason : null)) {
            throw new ValidationException(['address_id' => 'That address has no listing for the current event.']);
        }
        $this->audit->record($adminId, 'admin#'.$adminId, $hide ? 'listing.hidden' : 'listing.unhidden', 'address', (string) $addressId, ['reason' => $reason, 'event_id' => $event->id], 'admin');
    }

    /** Private CSV export (organizer only): address, zone, owner email, claim time, the active event's listing status. */
    public function exportCsv(): string
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.display_address, a.zone_key, a.latitude, a.longitude, u.email_normalized, c.claimed_at,
                    l.status, l.published, l.moderation_hidden, w.local_date AS event_date, w.start_local, w.end_local
             FROM claims c JOIN eligible_addresses a ON a.id = c.address_id JOIN users u ON u.id = c.user_id
             LEFT JOIN event_listings l ON l.address_id = c.address_id AND l.event_id = :event
             LEFT JOIN LATERAL (SELECT local_date, start_local, end_local FROM event_listing_windows WHERE listing_id = l.id ORDER BY local_date LIMIT 1) w ON TRUE
             ORDER BY a.street, a.house_number',
            ['event' => $this->community->current()->event->id ?? 0],
        );
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['address_id', 'address', 'zone', 'latitude', 'longitude', 'owner_email', 'claimed_at', 'status', 'published', 'hidden', 'event_date', 'start', 'end'], escape: '');
        foreach ($rows as $r) {
            $r = array_map(static fn ($v) => \is_string($v) && preg_match('~^[=+\-@\t\r]~', $v) ? "'".$v : $v, $r); // CSV injection guard
            fputcsv($fh, array_values($r), escape: '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.uP');
    }
}
