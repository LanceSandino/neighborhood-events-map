<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Audit\AuditLog;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/Api/EventsAdminApiController
 * @description Organizer API for events (ROLE_ADMIN, CSRF and idle timeout enforced first): list events with their
 *     opted-in and public counts, prepare a draft of an offered type, edit a draft or the active event, activate a
 *     draft (naming the event it replaces), archive, export one event's listings (a CSRF-checked form POST, like the
 *     claims export) and delete an archived event's listings. Every change is audited (Event/EventLifecycle).
 *
 * @dependencies Event/*, Audit/AuditLog, Shared/*
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
    ) {
    }

    #[Route('', methods: ['GET'], name: 'admin_events')]
    public function list(): JsonResponse
    {
        $active = $this->occurrences->active();

        return new JsonResponse([
            'events' => array_map(fn (array $e) => $e + ['type_name' => $this->typeName($e['type']), 'type_offered' => $this->types->isEnabled($e['type'])], $this->occurrences->allWithCounts()),
            'types' => array_map(static fn (EventType $t) => ['key' => $t->key(), 'name' => $t->defaultName(), 'schedule_kind' => $t->scheduleKind(), 'max_days' => $t->maxDays(),
                'route_profiles' => $t->routeProfiles(), 'settings' => $t instanceof EventSettingsType ? $t->settingKeys() : [],
                'setting_options' => $t instanceof EventSettingsType ? (object) $t->settingOptions() : (object) []], $this->types->enabled()),
            'active_id' => $active?->id,
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

    #[Route('/{id}/activate', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_activate')]
    public function activate(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        $body = JsonBody::decode($request);
        if (true !== ($body['confirm'] ?? false) || !\array_key_exists('replaces', $body) || !(null === $body['replaces'] || \is_int($body['replaces']))) {
            return ApiError::response(422, 'confirmation_required', 'Confirm the switch and name the event it replaces (replaces: its id, or null when none is active).');
        }

        return $this->conflicts(fn () => new JsonResponse(['event' => $this->lifecycle->activate($id, $body['replaces'], $admin->id)->toArray()]));
    }

    #[Route('/{id}/archive', requirements: ['id' => '\d{1,12}'], methods: ['POST'], name: 'admin_events_archive')]
    public function archive(#[CurrentUser] User $admin, Request $request, int $id): Response
    {
        if (true !== (JsonBody::decode($request)['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm that the event ends: its homes leave the map.');
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
                'Content-Disposition' => sprintf('attachment; filename="event-%d-%s.csv"', $event->id, $event->startsOn), 'Cache-Control' => 'no-store']);
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
