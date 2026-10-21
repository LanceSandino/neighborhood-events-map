<?php

declare(strict_types=1);

namespace App\Security\Session;

use App\Audit\AuditLog;
use App\Security\SessionKeys;
use App\Security\SessionPolicy;
use App\Security\User;
use App\Shared\ApiError;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @module Security/Session/IdleSessionSubscriber
 * @description Server-enforced inactivity timeout for signed-in residents/organizers. Runs on every
 *     protected request after the firewall loads the session token and before any controller.
 *     Elapsed >= idle window → session destroyed server-side, 401 {code:"session_expired"} (API)
 *     or redirect to the sign-in page. Only interactive requests extend the window; requests marked
 *     background (X-NHM-Background: 1) and the session-status probe never do. Sessions also end a fixed
 *     time after sign-in (AUTH_SESSION_MAX_HOURS, default 12) however active they are, and every session that began
 *     before the user chose "Sign out everywhere" ends too. Client timers are hints only.
 *
 * @dependencies Security/SessionPolicy, clock, token storage
 */
final class IdleSessionSubscriber implements EventSubscriberInterface
{
    public const BACKGROUND_HEADER = 'X-NHM-Background';

    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly SessionPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Firewall listens at priority 8; run right after it, before controllers.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !SessionPolicy::isProtectedPath($request->getPathInfo())) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            return; // access control already handles anonymous requests
        }
        $session = $request->getSession();
        $last = $session->get(SessionKeys::LAST_ACTIVITY);
        $loginAt = $session->get(SessionKeys::LOGIN_AT);
        $now = $this->clock->now()->getTimestamp();
        $idle = !\is_int($last) || $now - $last >= $this->policy->idleSeconds();
        $tooOld = \is_int($loginAt) && $now - $loginAt >= $this->policy->maxSessionSeconds();
        // "Sign out everywhere" ends every session that began before (or in the same second as) the request.
        $ended = null !== $user->sessionsEndedAt && (!\is_int($loginAt) || $loginAt <= $user->sessionsEndedAt);
        if ($idle || $tooOld || $ended) {
            $session->invalidate();
            $this->tokens->setToken(null);
            $this->audit->record($user->id, 'user#'.$user->id, $ended ? 'auth.session_ended' : 'auth.session_expired', 'user', (string) $user->id, [], 'system');
            $event->setResponse($this->expiredResponse($request, $ended));

            return;
        }
        if (!self::isBackground($request)) {
            $session->set(SessionKeys::LAST_ACTIVITY, $now);
        }
    }

    public static function isBackground(Request $request): bool
    {
        return '1' === $request->headers->get(self::BACKGROUND_HEADER) || '/api/me/session' === $request->getPathInfo();
    }

    private function expiredResponse(Request $request, bool $ended = false): \Symfony\Component\HttpFoundation\Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return $ended
                ? ApiError::response(401, 'session_expired', 'You were signed out on every device. Email yourself a new sign-in link.')
                : ApiError::response(401, 'session_expired', 'Your session ended after inactivity. Email yourself a new sign-in link.');
        }
        $next = $request->isMethod('GET') ? $request->getPathInfo() : '/me';

        return new RedirectResponse('/login?expired=1&next='.rawurlencode($next));
    }
}
