<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use App\Event\EventOccurrence;
use App\Event\EventBranding;
use App\Event\EventConflictException;
use App\Event\EventLifecycle;
use App\Event\EventListingExport;
use App\Event\EventOccurrenceRepository;
use App\Event\EventSettingsType;
use App\Event\EventType;
use App\Event\EventTypeRegistry;
use App\Security\User;
use App\Shared\ApiError;
use App\Shared\JsonBody;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/Api/EventsAdminApiController
 * @description Organizer API for events (ROLE_ADMIN, CSRF and idle timeout enforced first; ADR 0011): list events with
 *     their opted-in and public counts (an open event also with what closing it will do: the thank-you text and next
 *     year's dates), the types with the dates a new event gets and their default branding (the placeholders of an
 *     event's own branding, ADR 0012), prepare a draft of an offered type, edit an event (its branding too),
 *     announce a draft (upcoming), open, close (confirmed; creates next year's event), make an event the default,
 *     archive, export one event's listings (a CSRF-checked form POST, like the claims export) and delete an archived
 *     event's listings. Every change is audited (Event/EventLifecycle).
 *
 * @dependencies Event/*, Community/CommunityRepository (the community's local date), Audit/AuditLog, Shared/*, clock
 */
#[Route('/api/admin/events')]
#[IsGranted('ROLE_ADMIN')] // also enforced by access_control; declared here so a route rule is never the only lock
final class EventsAdminApiController
{
    public function __construct(
        private readonly EventLifecycle $lifecycle,
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventTypeRegistry $types,
        private readonly AuditLog $audit,
        private readonly CommunityRepository $community,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', methods: ['GET'], name: 'admin_events')]
    public function list(): JsonResponse
    {
        $today = $this->community->current()->localDate($this->clock->now());
        $events = array_map(function (array $e): array {
            $view = $e + ['type_name' => $this->typeName($e['type']), 'type_offered' => $this->types->isEnabled($e['type'])];
            if (EventOccurrence::OPEN === $e['state'] && $view['type_offered']) {
                $view['close_preview'] = $this->lifecycle->closePreview($this->lifecycle->get((int) $e['id']));
            }

            return $view;
        }, $this->occurrences->allWithCounts());
        $default = array_values(array_filter($events, static fn (array $e) => true === $e['is_default']))[0]['id'] ?? null;

        return new JsonResponse([
            'events' => $events,
            'types' => array_map(static fn (EventType $t) => ['key' => $t->key(), 'name' => $t->defaultName(), 'schedule_kind' => $t->scheduleKind(), 'max_days' => $t->maxDays(),
                'route_profiles' => $t->routeProfiles(), 'settings' => $t instanceof EventSettingsType ? $t->settingKeys() : [],
                'setting_options' => $t instanceof EventSettingsType ? (object) $t->settingOptions() : (object) [],
                'new_dates' => $t->newEventDates($today), 'closing_template' => (string) ($t->copy()['page_closing_message'] ?? ''),
                // The type's branding, shown as the placeholders of an event's own (ADR 0012).
                'branding' => EventBranding::look($t, []) + ['about' => (string) ($t->copy()['about'] ?? '')]], $this->types->enabled()),
            'default_id' => $default,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('', methods: ['POST'], name: 'admin_events_create')]
    public function create(#[CurrentUser] User $admin, Request $request): JsonResponse
    {
        return new JsonResponse(['event' => $this->lifecycle->createDraft(JsonBody::decode($request), $admin->id)->toArray()], 201);
    }

    #[Route('/{id}', requirements: ['id' => '\d{1,12}'], methods: ['PUT'], name: 'admin_events_update')]
    public function update(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        $body = JsonBody::decode($request);
        $move = true === ($body['move_listings'] ?? false);
        unset($body['move_listings']);

        return $this->conflicts(fn () => new JsonResponse(['event' => $this->lifecycle->update($id, $body, $admin->id, $move)->toArray()]));
    }

    #[Route('/{id}/announce', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_announce')]
    public function announce(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        if (true !== (JsonBody::decode($request)['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm the announcement: visitors see the event and its dates, and residents may list.');
        }

        return $this->conflicts(fn () => new JsonResponse(['event' => $this->lifecycle->announce($id, $admin->id)->toArray()]));
    }

    #[Route('/{id}/open', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_open')]
    public function open(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        if (true !== (JsonBody::decode($request)['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm that the event opens: its homes appear on the map and its dates are confirmed.');
        }

        return $this->conflicts(fn () => new JsonResponse(['event' => $this->lifecycle->open($id, $admin->id)->toArray()]));
    }

    /** Closes an open event; next year's event of its type is created at the same time (upcoming). */
    #[Route('/{id}/close', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_close')]
    public function close(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        $body = JsonBody::decode($request);
        if (true !== ($body['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm that the event closes: its homes leave the map, visitors see the thank-you message and next year\'s event is created.');
        }
        $message = \array_key_exists('closing_message', $body) ? (\is_string($body['closing_message']) ? $body['closing_message'] : '') : null;

        return $this->conflicts(function () use ($admin, $id, $message): Response {
            $r = $this->lifecycle->close($id, $message, $admin->id);

            return new JsonResponse(['event' => $r['closed']->toArray(), 'next' => $r['next']->toArray()]);
        });
    }

    #[Route('/{id}/default', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_default')]
    public function makeDefault(#[CurrentUser] User $admin, int $id): Response
    {
        return $this->conflicts(fn () => new JsonResponse(['event' => $this->lifecycle->makeDefault($id, $admin->id)->toArray()]));
    }

    #[Route('/{id}/archive', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_archive')]
    public function archive(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        if (true !== (JsonBody::decode($request)['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm the archive: the event and its homes leave the public site for good.');
        }

        return $this->conflicts(fn () => new JsonResponse(['event' => $this->lifecycle->archive($id, $admin->id)->toArray()]));
    }

    #[Route('/{id}/purge-listings', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_purge')]
    public function purge(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        if ('DELETE' !== (JsonBody::decode($request)['confirm'] ?? null)) {
            return ApiError::response(422, 'confirmation_required', 'Type DELETE to remove this event\'s listings for good.');
        }

        return $this->conflicts(fn () => new JsonResponse(['purged' => $this->lifecycle->purgeListings($id, $admin->id)]));
    }

    /** A POST (with the CSRF token as a form field): the export writes an audit event, so no link or prefetch may trigger it. */
    #[Route('/{id}/export', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_export')]
    public function export(#[CurrentUser] User $admin, int $id, EventListingExport $export): Response
    {
        return $this->conflicts(function () use ($admin, $id, $export): Response {
            $event = $this->lifecycle->get($id);
            $this->audit->record($admin->id, 'admin#'.$admin->id, 'event.exported', 'event', (string) $id, [], 'admin');

            return new Response($export->csv($event), 200, ['Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => sprintf('attachment; filename="event-%d-%s.csv"', $event->id, $event->startsOn ?? 'tbd'), 'Cache-Control' => 'no-store']);
        });
    }

    private function typeName(string $key): string
    {
        try {
            return $this->types->get($key)->defaultName();
        } catch (\InvalidArgumentException) {
            return $key; // a type this version does not know: shown by its key, still archivable
        }
    }

    /** @param callable(): Response $fn */
    private function conflicts(callable $fn): Response
    {
        try {
            return $fn();
        } catch (EventConflictException $e) {
            return ApiError::response('not_found' === $e->reason ? 404 : 409, $e->reason, $e->getMessage());
        }
    }
}
