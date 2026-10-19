<?php

declare(strict_types=1);

namespace App\Security;

/**
 * @module Security/SessionKeys
 * @description Names of server-side session attributes used for authentication lifecycle.
 */
final class SessionKeys
{
    public const LAST_ACTIVITY = '_nhm.last_activity';
    public const LOGIN_AT = '_nhm.login_at';
    public const CLAIM_OUTCOME = '_nhm.claim_outcome';
    public const NEXT = '_nhm.next';
}
