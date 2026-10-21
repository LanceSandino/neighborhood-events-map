<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AccountLedger;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Security/UserRepository
 * @description Persistence for passwordless users keyed by normalized email. The organizer role is
 *     only granted out-of-band — `app:admin:grant` or ORGANIZER_EMAILS in the server environment —
 *     never self-assigned; a verified sign-in link proves control of the listed address. Role changes go to the
 *     account ledger, so a restored backup cannot bring a revoked organizer back.
 */
final class UserRepository
{
    /** @var array<string,true> organizers named in configuration (ORGANIZER_EMAILS) */
    private readonly array $configuredOrganizers;

    private const COLUMNS = 'id, email_normalized, is_admin, sessions_ended_at';

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock, private readonly AccountLedger $ledger, string $organizerEmails = '')
    {
        $list = [];
        foreach (preg_split('~[\s,;]+~', strtolower($organizerEmails)) ?: [] as $e) {
            if (false !== filter_var($e, \FILTER_VALIDATE_EMAIL)) {
                $list[$e] = true;
            }
        }
        $this->configuredOrganizers = $list;
    }

    /** True for organizers granted with app:admin:grant or listed in ORGANIZER_EMAILS. */
    public function isOrganizerEmail(string $email): bool
    {
        return isset($this->configuredOrganizers[$email])
            || true === (bool) $this->db->fetchOne('SELECT is_admin FROM users WHERE email_normalized = ?', [$email]);
    }

    public function find(int $id): ?User
    {
        $row = $this->db->fetchAssociative('SELECT '.self::COLUMNS.' FROM users WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchAssociative('SELECT '.self::COLUMNS.' FROM users WHERE email_normalized = ?', [$email]);

        return false === $row ? null : $this->hydrate($row);
    }

    /** Creates or marks verified, recording the login time. Safe under concurrency (upsert). */
    public function upsertVerified(string $email): User
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $row = $this->db->fetchAssociative(
            'INSERT INTO users (email_normalized, verified_at, created_at, last_login_at) VALUES (:e, :now, :now, :now)
             ON CONFLICT (email_normalized) DO UPDATE SET verified_at = COALESCE(users.verified_at, EXCLUDED.verified_at), last_login_at = EXCLUDED.last_login_at
             RETURNING '.self::COLUMNS,
            ['e' => $email, 'now' => $now],
        );

        return $this->hydrate($row ?: throw new \RuntimeException('User upsert failed'));
    }

    /** Ensures an (unverified) account row exists, e.g. for an organizer-initiated transfer. */
    public function ensure(string $email): User
    {
        $row = $this->db->fetchAssociative(
            'INSERT INTO users (email_normalized, created_at) VALUES (:e, :now)
             ON CONFLICT (email_normalized) DO UPDATE SET email_normalized = EXCLUDED.email_normalized
             RETURNING '.self::COLUMNS,
            ['e' => $email, 'now' => $this->clock->now()->format('Y-m-d H:i:s.uP')],
        );

        return $this->hydrate($row ?: throw new \RuntimeException('User ensure failed'));
    }

    public function setAdmin(string $email, bool $admin): User
    {
        $user = $this->ensure($email);
        $this->db->transactional(function (Connection $db) use ($user, $admin): void {
            $db->executeStatement('UPDATE users SET is_admin = :a WHERE id = :id', ['a' => $admin, 'id' => $user->id], ['a' => 'boolean']);
            $this->ledger->record($admin ? 'admin.granted' : 'admin.revoked', $user->id);
        });

        return new User($user->id, $user->email, $admin || isset($this->configuredOrganizers[$user->email]), $user->sessionsEndedAt);
    }

    /** "Sign out everywhere": every session of this user that began up to now is over (IdleSessionSubscriber). */
    public function endSessions(int $id): void
    {
        $this->db->executeStatement('UPDATE users SET sessions_ended_at = ? WHERE id = ?', [$this->clock->now()->format('Y-m-d H:i:s.uP'), $id]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): User
    {
        $email = (string) $row['email_normalized'];
        $ended = null === $row['sessions_ended_at'] ? null : (new \DateTimeImmutable((string) $row['sessions_ended_at']))->getTimestamp();

        return new User((int) $row['id'], $email, (bool) $row['is_admin'] || isset($this->configuredOrganizers[$email]), $ended);
    }
}
