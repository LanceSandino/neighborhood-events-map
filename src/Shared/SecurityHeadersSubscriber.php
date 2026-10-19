<?php

declare(strict_types=1);

namespace App\Shared;

use App\Community\CommunityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @module Shared/SecurityHeadersSubscriber
 * @description Adds a strict Content-Security-Policy (no inline script/style; tile host from config),
 *     framing/referrer/permissions headers, and no-store caching for private areas.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CommunityRepository $community)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $h = $response->headers;
        if (!$h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', $this->csp());
        }
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        // Tile providers (OSM policy) require a Referer: send only our origin cross-site, never paths.
        // One-time sign-in pages never leak their URL cross-site ("same-origin"); "no-referrer" would make
        // browsers send Origin: null on the confirm POST and break the same-origin check.
        $h->set('Referrer-Policy', str_starts_with($request->getPathInfo(), '/auth/') ? 'same-origin' : 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()');
        if ($request->isSecure()) {
            $h->set('Cross-Origin-Opener-Policy', 'same-origin');
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if (preg_match('~^/(me|admin|auth|login|api/me|api/admin|api/auth)~', $request->getPathInfo())) {
            $h->set('Cache-Control', 'no-store, private');
        }
    }

    private function csp(): string
    {
        $tiles = 'https://tile.openstreetmap.org';
        try {
            $url = (string) $this->community->current()->settings['map']['tiles']['url'];
            $p = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], $url));
            if (isset($p['scheme'], $p['host'])) {
                $host = str_contains($url, '{s}') ? '*.'.implode('.', \array_slice(explode('.', $p['host']), 1)) : $p['host'];
                $tiles = $p['scheme'].'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '');
            }
        } catch (\Throwable) {
            // DB unavailable: keep the default tile host.
        }

        return "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: {$tiles}; font-src 'self'; "
            ."connect-src 'self'; worker-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
    }
}
