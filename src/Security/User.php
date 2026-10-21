<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @module Security/User
 * @description Authenticated resident/organizer identity (passwordless; no credentials stored). sessionsEndedAt
 *     (Unix time) is when the user last chose "Sign out everywhere": sessions that began before it are over.
 */
final class User implements UserInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly bool $isAdmin,
        public readonly ?int $sessionsEndedAt = null,
    ) {
    }

    public function getRoles(): array
    {
        return $this->isAdmin ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
    }

    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'isAdmin' => $this->isAdmin, 'sessionsEndedAt' => $this->sessionsEndedAt];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->isAdmin = $data['isAdmin'];
        $this->sessionsEndedAt = $data['sessionsEndedAt'] ?? null;
    }
}
