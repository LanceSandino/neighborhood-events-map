<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @module Event/EventSelection
 * @description Which event a request is about (ADR 0011, D-20261031-38): the query parameter `e=<event id>` on any
 *     public page or API, My house and the resident's writes (claims, listings, route plans) selects a public event
 *     (upcoming, open or closed, of a type this release offers); without it the request gets the default event. Any other
 *     value — not a number, an unknown id, a draft, an archived event — answers an API request with 404 exactly like an
 *     unknown id, while a page shows the default event (a shared link made for an event that is gone then says so in the
 *     browser and shows no stops), so ids reveal nothing either way. The organizer console (/admin, /api/admin) chooses
 *     its events itself and ignores the parameter. My house (/me, its /me/theme.css, /api/me — residents only, by access_control and the
 *     controllers' roles) may also name a hidden event, which residents get ready for (D-20261101-01); everywhere else a
 *     hidden event is exactly an unknown id.
 *
 * @dependencies Community/CommunityRepository, Symfony HttpKernel
 */
final class EventSelection implements EventSubscriberInterface
{
    public const PARAM = 'e';

    public function __construct(private readonly CommunityRepository $community)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (32), before the origin/CSRF guard (9/6), the firewall (8) and every controller. My house
        // resolves after the firewall, the idle check and the CSRF guard (5), so a visitor who is not signed in learns nothing about a hidden id (401
        // whatever the id) and a resident's unknown id is still 404.
        return [KernelEvents::REQUEST => [['onRequest', 30], ['onMyHouseRequest', 5]]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->community->select(null);
        $path = $event->getRequest()->getPathInfo();
        if (!self::isMyHouse($path)) {
            $this->resolve($event, $path, false);
        }
    }

    public function onMyHouseRequest(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ($event->isMainRequest() && self::isMyHouse($path)) {
            $this->resolve($event, $path, true);
        }
    }

    private function resolve(RequestEvent $event, string $path, bool $resident): void
    {
        $request = $event->getRequest();
        if (!$request->query->has(self::PARAM) || str_starts_with($path, '/admin') || str_starts_with($path, '/api/admin')) {
            return;
        }
        $raw = $request->query->all()[self::PARAM] ?? null;
        $id = \is_string($raw) && 1 === preg_match('~^[1-9]\d{0,11}$~', $raw) ? (int) $raw : null;
        $selected = null === $id ? null : ($resident ? $this->community->residentEvent($id) : $this->community->publicEvent($id));
        if (null === $selected) {
            if (str_starts_with($path, '/api/')) {
                throw new NotFoundHttpException('That event is not on the map.');
            }

            return; // a page: the default event
        }
        $this->community->select($selected);
    }

    /** My house: the page and its API (ROLE_USER); a hidden event may be named here only. */
    private static function isMyHouse(string $path): bool
    {
        return '/me' === $path || '/me/theme.css' === $path || '/api/me' === $path || str_starts_with($path, '/api/me/');
    }
}
