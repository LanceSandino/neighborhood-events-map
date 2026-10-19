<?php

declare(strict_types=1);

namespace App\Audit;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Audit/AuditLog
 * @description Append-only audit trail of claims, edits, moderation, transfers and config changes.
 *     Details are sanitized by callers (no tokens; emails masked unless an admin action needs them).
 */
final class AuditLog
{
    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    /** @param array<string,mixed> $details */
    public function record(?int $actorUserId, string $actorLabel, string $action, string $targetType, ?string $targetId, array $details = [], string $source = 'web'): void
    {
        $this->db->insert('audit_events', [
            'actor_user_id' => $actorUserId,
            'actor_label' => mb_substr($actorLabel, 0, 120),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => json_encode($details, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 200, ?string $targetType = null, ?string $targetId = null): array
    {
        $sql = 'SELECT * FROM audit_events';
        $params = [];
        if (null !== $targetType) {
            $sql .= ' WHERE target_type = :t AND target_id = :id';
            $params = ['t' => $targetType, 'id' => $targetId];
        }

        return $this->db->fetchAllAssociative($sql.' ORDER BY id DESC LIMIT '.max(1, min(1000, $limit)), $params);
    }
}
