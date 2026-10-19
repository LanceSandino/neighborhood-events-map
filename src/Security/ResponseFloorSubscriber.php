<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @module Security/ResponseFloorSubscriber
 * @description Minimum response time (plus random jitter) for email-sending endpoints, so response
 *     timing reveals nothing about the address, its claim state or mail-relay latency. Controllers
 *     opt in with mark() only after proof-of-work has been verified, so the wait can never be used to
 *     tie up PHP workers cheaply. Every outcome after that point (accepted, invalid, conflict,
 *     rate-limited, mail failure) is padded alike.
 */
final class ResponseFloorSubscriber implements EventSubscriberInterface
{
    private const STARTED = '_nhm_started_ns';
    private const FLOOR = '_nhm_response_floor';

    public function __construct(private readonly int $floorMs, private readonly int $jitterMs)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
        ];
    }

    public static function mark(Request $request): void
    {
        $request->attributes->set(self::FLOOR, true);
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::STARTED, hrtime(true));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || true !== $request->attributes->get(self::FLOOR) || $this->floorMs <= 0) {
            return;
        }
        $started = (int) $request->attributes->get(self::STARTED, hrtime(true));
        $target = $this->floorMs + ($this->jitterMs > 0 ? random_int(0, $this->jitterMs) : 0);
        $remainingUs = (int) (($target * 1_000_000 - (hrtime(true) - $started)) / 1000);
        if ($remainingUs > 0) {
            usleep(min($remainingUs, 5_000_000));
        }
    }
}
