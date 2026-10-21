<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\ApiError;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @module Security/CanonicalPathSubscriber
 * @description Refuses any request whose path carries percent-encoding, before sessions, the firewall,
 *     the CSRF/origin guard or routing run. Symfony routes and authorizes on the decoded path while
 *     several guards read the raw one (Request::getPathInfo()), so `/api/m%65` reached the signed-in API
 *     without the idle-timeout and CSRF checks and `/_internal_%6Cogout` reached the logout listener.
 *     No route of this app needs an encoded path; query strings are unaffected. nginx refuses the
 *     same paths first (docker/nginx/default.conf); this keeps the app safe on its own.
 */
final class CanonicalPathSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Before the session listener (128), the origin/CSRF guard (9/6), the router (32) and the firewall (8).
        return [KernelEvents::REQUEST => ['onRequest', 512]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && str_contains($event->getRequest()->getPathInfo(), '%')) {
            $event->setResponse(ApiError::response(404, 'not_found', 'Not found.'));
        }
    }
}
