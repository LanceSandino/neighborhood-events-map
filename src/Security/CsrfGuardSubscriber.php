<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\ApiError;
use App\Shared\RuntimeConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * @module Security/CsrfGuardSubscriber
 * @description Two layers of request-forgery defense for every state-changing request:
 *     (1) before the firewall: Origin (or Referer) must match this site — anonymous APIs included;
 *     (2) after the firewall: signed-in mutations need the session CSRF token (header X-CSRF-Token
 *     or form field _csrf). Logout only via POST.
 */
final class CsrfGuardSubscriber implements EventSubscriberInterface
{
    public const TOKEN_ID = 'nhm';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly RuntimeConfig $config,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['checkOrigin', 9], ['checkToken', 6]]];
    }

    public function checkOrigin(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }
        if ('/_internal_logout' === $request->getPathInfo()) {
            $event->setResponse(ApiError::response(404, 'not_found', 'Not found.'));

            return;
        }
        if ('/api/auth/logout' === $request->getPathInfo() && !$request->isMethod('POST')) {
            $event->setResponse(ApiError::response(405, 'method_not_allowed', 'Use POST to sign out.'));

            return;
        }
        if (!self::isMutating($request)) {
            return;
        }
        if (!$this->sameOrigin($request)) {
            $event->setResponse(ApiError::response(403, 'bad_origin', 'Cross-site request blocked.'));
        }
    }

    public function checkToken(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isMutating($request)) {
            return;
        }
        $path = $request->getPathInfo();
        $needsToken = 1 === preg_match('~^/(api/me|api/admin|admin|me)(/|$)~', $path) || '/api/auth/logout' === $path;
        if (!$needsToken || null === $this->tokens->getToken()) {
            return; // anonymous → access control responds 401
        }
        $value = (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf', ''));
        if (!$this->csrf->isTokenValid(new CsrfToken(self::TOKEN_ID, $value))) {
            $event->setResponse(ApiError::response(403, 'csrf_invalid', 'Your page is out of date. Reload and try again.'));
        }
    }

    public static function isMutating(Request $request): bool
    {
        return !\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function sameOrigin(Request $request): bool
    {
        $allowed = [strtolower($request->getSchemeAndHttpHost())];
        $base = parse_url($this->config->baseUrl);
        if (isset($base['scheme'], $base['host'])) {
            $allowed[] = strtolower($base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : ''));
        }
        $origin = $request->headers->get('Origin');
        if (null !== $origin && 'null' !== $origin) {
            return \in_array(strtolower(rtrim($origin, '/')), $allowed, true);
        }
        $referer = $request->headers->get('Referer');
        if (null !== $referer) {
            $r = parse_url($referer);
            if (isset($r['scheme'], $r['host'])) {
                return \in_array(strtolower($r['scheme'].'://'.$r['host'].(isset($r['port']) ? ':'.$r['port'] : '')), $allowed, true);
            }
        }

        return false;
    }
}
