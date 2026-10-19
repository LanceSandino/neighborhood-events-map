<?php

declare(strict_types=1);

namespace App\Security\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * @module Security/Session/PgSessionHandler
 * @description Durable PostgreSQL session storage (survives container restarts) using its OWN
 *     lazily-opened PDO connection with advisory locking, so session locking never interferes with
 *     the application's DBAL transactions and public pages never open it. Idle expiry is enforced
 *     by IdleSessionSubscriber, not by this handler's GC.
 */
final class PgSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private ?PdoSessionHandler $inner = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    private function inner(): PdoSessionHandler
    {
        if (null === $this->inner) {
            $p = $this->connection->getParams();
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'] ?? 'localhost', (int) ($p['port'] ?? 5432), $p['dbname'] ?? '');
            $pdo = new \PDO($dsn, $p['user'] ?? null, $p['password'] ?? null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->inner = new PdoSessionHandler($pdo, ['db_table' => 'sessions', 'lock_mode' => PdoSessionHandler::LOCK_ADVISORY]);
        }

        return $this->inner;
    }

    public function open(string $path, string $name): bool
    {
        return $this->inner()->open($path, $name);
    }

    public function close(): bool
    {
        return null === $this->inner || $this->inner->close();
    }

    public function read(string $id): string|false
    {
        return $this->inner()->read($id);
    }

    public function write(string $id, string $data): bool
    {
        return $this->inner()->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->inner()->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->inner()->gc($max_lifetime);
    }

    public function validateId(string $id): bool
    {
        return $this->inner()->validateId($id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->inner()->updateTimestamp($id, $data);
    }
}
