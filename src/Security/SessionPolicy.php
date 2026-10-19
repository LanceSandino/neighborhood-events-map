<?php

declare(strict_types=1);

namespace App\Security;

use App\Community\CommunityRepository;
use App\Shared\RuntimeConfig;

/**
 * @module Security/SessionPolicy
 * @description Single source of truth for the idle timeout (env AUTH_SESSION_IDLE_MINUTES >
 *     community.yaml registration.session_idle_minutes > 30) and the magic-link lifetime
 *     (env MAGIC_LINK_MINUTES > YAML > 15). The two clocks are independent.
 */
final class SessionPolicy
{
    public function __construct(private readonly RuntimeConfig $config, private readonly CommunityRepository $community, private readonly int $maxSessionHours = 12)
    {
    }

    /** Absolute session lifetime: a session ends this long after sign-in even if it stays active (1–72 h). */
    public function maxSessionSeconds(): int
    {
        return 3600 * max(1, min(72, $this->maxSessionHours));
    }

    public function idleSeconds(): int
    {
        return 60 * $this->config->sessionIdleMinutes($this->community->current()->settings['registration']['session_idle_minutes'] ?? null);
    }

    public function linkMinutes(): int
    {
        return $this->config->magicLinkMinutes($this->community->current()->settings['registration']['email_magic_link_minutes'] ?? null);
    }

    public static function isProtectedPath(string $path): bool
    {
        return 1 === preg_match('~^/(me|admin|api/me|api/admin)(/|$)~', $path);
    }
}
