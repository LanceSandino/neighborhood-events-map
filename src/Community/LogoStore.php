<?php

declare(strict_types=1);

namespace App\Community;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * @module Community/LogoStore
 * @description Logos in the database (D-20261105-03): one for the community and one per event, so a backup and a restore
 *     include them. A logo's URL carries a hash of its content, so a changed logo is never served from a cache. Bytes are
 *     checked by Community/BrandingStore before they get here. Displayed by order of the page: an event's page shows its
 *     own logo, else its type's drawn mark; the organizer console and pages without an event show the community's logo,
 *     else a neutral drawn mark. A database that has not reached the migration yet simply has no logos.
 *
 * @dependencies DBAL
 */
final class LogoStore
{
    private const TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{extension:string, sha256:string}|null without the bytes (null event: the community's logo) */
    public function meta(?int $eventId): ?array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT extension, sha256 FROM logos WHERE event_id IS NOT DISTINCT FROM CAST(:e AS BIGINT)', ['e' => $eventId]);
        } catch (TableNotFoundException) {
            return null;
        }

        return false === $row ? null : ['extension' => (string) $row['extension'], 'sha256' => (string) $row['sha256']];
    }

    /** The logo's public URL, with a content version; null when it has none. */
    public function url(?int $eventId): ?string
    {
        $meta = $this->meta($eventId);
        if (null === $meta) {
            return null;
        }
        $path = null === $eventId ? '/branding/logo.' : '/branding/event-'.$eventId.'/logo.';

        return $path.$meta['extension'].'?v='.substr($meta['sha256'], 0, 10);
    }

    /** The stored file name the console shows ("logo.svg"). */
    public function name(?int $eventId): ?string
    {
        $meta = $this->meta($eventId);

        return null === $meta ? null : 'logo.'.$meta['extension'];
    }

    /** @return array{extension:string, type:string, bytes:string}|null */
    public function load(?int $eventId): ?array
    {
        try {
            $row = $this->db->fetchAssociative("SELECT extension, encode(content, 'base64') AS content FROM logos WHERE event_id IS NOT DISTINCT FROM CAST(:e AS BIGINT)", ['e' => $eventId]);
        } catch (TableNotFoundException) {
            return null;
        }
        if (false === $row) {
            return null;
        }
        $extension = (string) $row['extension'];

        return ['extension' => $extension, 'type' => self::TYPES[$extension], 'bytes' => (string) base64_decode((string) $row['content'], true)];
    }

    /** Stores a checked logo (Community/BrandingStore::check), replacing the owner's. */
    public function put(?int $eventId, string $extension, string $bytes): void
    {
        $this->db->executeStatement(
            "INSERT INTO logos (event_id, extension, content, sha256, updated_at) VALUES (:e, :x, decode(:c, 'base64'), :h, NOW())
             ON CONFLICT ON CONSTRAINT uniq_logo_owner DO UPDATE SET extension = EXCLUDED.extension, content = EXCLUDED.content, sha256 = EXCLUDED.sha256, updated_at = EXCLUDED.updated_at",
            ['e' => $eventId, 'x' => $extension, 'c' => base64_encode($bytes), 'h' => hash('sha256', $bytes)],
        );
    }

    public function remove(?int $eventId): void
    {
        $this->db->executeStatement('DELETE FROM logos WHERE event_id IS NOT DISTINCT FROM CAST(:e AS BIGINT)', ['e' => $eventId]);
    }

    /** Next year's event keeps the logo of the event it follows, like the rest of its branding. */
    public function copy(int $fromEvent, int $toEvent): void
    {
        $this->db->executeStatement(
            'INSERT INTO logos (event_id, extension, content, sha256, updated_at) SELECT :to, extension, content, sha256, NOW() FROM logos WHERE event_id = :from
             ON CONFLICT ON CONSTRAINT uniq_logo_owner DO NOTHING',
            ['to' => $toEvent, 'from' => $fromEvent],
        );
    }

    /** Whether the table exists; inside a transaction a failed query would abort it, so writers ask first. */
    public function available(): bool
    {
        return (bool) $this->db->fetchOne("SELECT to_regclass('public.logos') IS NOT NULL");
    }
}
