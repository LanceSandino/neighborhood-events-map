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
 *     its events itself and ignores the parameter.
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
        // After the router (32), before the origin/CSRF guard (9/6), the firewall (8) and every controller.
        return [KernelEvents::REQUEST => ['onRequest', 30]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $this->community->select(null);
        if (!$request->query->has(self::PARAM) || str_starts_with($path, '/admin') || str_starts_with($path, '/api/admin')) {
            return;
        }
        $raw = $request->query->all()[self::PARAM] ?? null;
        $selected = \is_string($raw) && 1 === preg_match('~^[1-9]\d{0,11}$~', $raw) ? $this->community->publicEvent((int) $raw) : null;
        if (null === $selected) {
            if (str_starts_with($path, '/api/')) {
                throw new NotFoundHttpException('That event is not on the map.');
            }

            return; // a page: the default event
        }
        $this->community->select($selected);
    }
}
