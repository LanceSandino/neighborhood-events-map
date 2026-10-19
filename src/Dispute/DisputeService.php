<?php

declare(strict_types=1);

namespace App\Dispute;

use App\Address\AddressRegistry;
use App\Audit\AuditLog;
use App\Community\CommunityConfig;
use App\Mail\Notifier;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * @module Dispute/DisputeService
 * @description Records "already claimed" review requests and emails the organizer. Never transfers
 *     anything automatically and never reveals the current owner. Organizer resolves/rejects.
 */
final class DisputeService
{
    public const REASON_MIN = 200;
    public const REASON_MAX = 2000;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly AddressRegistry $addresses,
        private readonly Notifier $notifier,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Checks a request before any per-home or global budget is spent, so invalid requests cannot be
     * used to block review requests for a home.
     *
     * @return array{reason:string, address:array<string,mixed>}
     */
    public function validate(int $addressId, string $reason): array
    {
        $reason = trim(preg_replace('~[\x00-\x09\x0B-\x1F\x7F]~u', '', $reason) ?? '');
        $len = mb_strlen($reason);
        if ($len < self::REASON_MIN || $len > self::REASON_MAX) {
            throw new ValidationException(['reason' => sprintf('Please explain in %d–%d characters (you have %d).', self::REASON_MIN, self::REASON_MAX, $len)]);
        }
        $address = $this->addresses->find($addressId);
        if (null === $address || !$this->addresses->isClaimed($addressId)) {
            throw new ValidationException(['address_id' => 'That home is not currently claimed, so there is nothing to review.']);
        }

        return ['reason' => $reason, 'address' => $address];
    }

    /** @return array{id:int, ticket:string, notified:bool} */
    public function open(int $addressId, string $reporterEmail, string $reason, CommunityConfig $community): array
    {
        ['reason' => $reason, 'address' => $address] = $this->validate($addressId, $reason);
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $id = (int) $this->db->fetchOne(
            'INSERT INTO disputes (address_id, reporter_email, reason, created_at) VALUES (?, ?, ?, ?) RETURNING id',
            [$addressId, $reporterEmail, $reason, $now],
        );
        $dispute = ['id' => $id, 'reporter_email' => $reporterEmail, 'reason' => $reason, 'created_at' => $now];
        $notified = false;
        try {
            $this->notifier->sendDisputeNotice($dispute, (string) $address['display_address'], $community);
            $this->db->executeStatement('UPDATE disputes SET notified_at = ? WHERE id = ?', [$now, $id]);
            $notified = true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Dispute notice email failed', ['dispute' => $id, 'error' => $e->getMessage()]);
        }
        $this->audit->record(null, 'public', 'dispute.opened', 'address', (string) $addressId, ['dispute' => $id, 'notified' => $notified], 'web');

        return ['id' => $id, 'ticket' => self::ticket($id), 'notified' => $notified];
    }

    public static function ticket(int $id): string
    {
        return sprintf('D-%06d', $id);
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT d.*, a.display_address, c.user_id AS owner_user_id, u.email_normalized AS owner_email
             FROM disputes d JOIN eligible_addresses a ON a.id = d.address_id
             LEFT JOIN claims c ON c.address_id = d.address_id LEFT JOIN users u ON u.id = c.user_id'
            .(null !== $status ? ' WHERE d.status = :s' : '').' ORDER BY d.status = \'open\' DESC, d.created_at DESC LIMIT 500',
            null !== $status ? ['s' => $status] : [],
        );
    }

    public function resolve(int $id, string $status, string $note, int $adminId): bool
    {
        if (!\in_array($status, ['resolved', 'rejected', 'open'], true)) {
            throw new ValidationException(['status' => 'Status must be resolved, rejected or open.']);
        }
        $n = $this->db->executeStatement(
            'UPDATE disputes SET status = :s, resolution_note = :n, resolved_by = :a, resolved_at = :t WHERE id = :id',
            ['s' => $status, 'n' => mb_substr(trim($note), 0, 2000), 'a' => 'open' === $status ? null : $adminId,
                't' => 'open' === $status ? null : $this->clock->now()->format('Y-m-d H:i:s.uP'), 'id' => $id],
        );
        if ($n > 0) {
            $this->audit->record($adminId, 'admin#'.$adminId, 'dispute.'.$status, 'dispute', (string) $id, ['note' => mb_substr($note, 0, 200)], 'admin');
        }

        return $n > 0;
    }
}
