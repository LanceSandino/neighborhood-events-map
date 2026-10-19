<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @module Security/UserProvider
 * @description Reloads the session user from PostgreSQL on every protected request so role
 *     changes (admin revoked) and deleted accounts take effect immediately.
 *
 * @implements UserProviderInterface<User>
 */
final class UserProvider implements UserProviderInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->users->find($user->id) ?? throw new UserNotFoundException();
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->users->findByEmail($identifier) ?? throw new UserNotFoundException();
    }
}
