<?php

declare(strict_types=1);

namespace App\Security\Session;

use App\Audit\AuditLog;
use App\Claim\ClaimOutcome;
use App\Security\SessionKeys;
use App\Security\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * @module Security/Session/LoginSessionSubscriber
 * @description After a successful magic-link login AND after the session-fixation strategy has
 *     replaced the session, starts the server-side idle clock and stores the one-time claim outcome.
 */
final class LoginSessionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ClockInterface $clock, private readonly AuditLog $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // SessionStrategyListener runs at priority 0; this must run after it.
        return [LoginSuccessEvent::class => ['onLogin', -128]];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $session->set(SessionKeys::LAST_ACTIVITY, $now);
        $session->set(SessionKeys::LOGIN_AT, $now);
        $outcome = $request->attributes->get('_nhm_claim_outcome');
        if ($outcome instanceof ClaimOutcome) {
            $session->set(SessionKeys::CLAIM_OUTCOME, ['status' => $outcome->status, 'address_id' => $outcome->addressId,
                'address' => $outcome->address, 'message' => $outcome->message]);
        }
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->audit->record($user->id, 'user#'.$user->id, 'auth.login', 'user', (string) $user->id, ['admin' => $user->isAdmin]);
        }
    }
}
