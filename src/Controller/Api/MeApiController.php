<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Audit\AuditLog;
use App\Claim\ClaimService;
use App\Community\CommunityRepository;
use App\Event\EventTypeRegistry;
use App\Listing\ListingDraft;
use App\Listing\ListingRepository;
use App\Listing\ResidentHomes;
use App\Security\SessionKeys;
use App\Security\SessionPolicy;
use App\Security\Throttle;
use App\Security\User;
use App\Security\UserRepository;
use App\Shared\ApiError;
use App\Shared\JsonBody;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/Api/MeApiController
 * @description Owner-only API (ROLE_USER, declared here and in access_control; CSRF on writes; idle
 *     timeout enforced before this runs). A resident's own homes are reached only through the
 *     owner-scoped ResidentHomes, so no query here can touch another resident's home. Listing details are
 *     validated by the community's event type (ListingDraft). Claims stay with the resident across events; each
 *     home shows its listing for the ACTIVE event, or can be listed for it from an empty form. The client never
 *     names an event: the server always uses the active one.
 *
 * @dependencies Listing/*, Claim/ClaimService, Community/CommunityRepository, Event/EventTypeRegistry, Security/*, Audit/AuditLog
 */
#[IsGranted('ROLE_USER')]
final class MeApiController
{
    public function __construct(
        private readonly ResidentHomes $residentHomes,
        private readonly CommunityRepository $community,
        private readonly ClaimService $claims,
        private readonly SessionPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly AuditLog $audit,
        private readonly Throttle $throttle,
        private readonly UserRepository $users,
        private readonly EventTypeRegistry $types,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $session = $request->getSession();
        $outcome = $session->get(SessionKeys::CLAIM_OUTCOME);
        $session->remove(SessionKeys::CLAIM_OUTCOME);

        return new JsonResponse([
            'user' => ['email' => $user->email, 'is_admin' => $user->isAdmin],
            'session' => $this->sessionInfo($request),
            'claim_outcome' => $outcome,
            'event_date' => $this->community->current()->eventDate(),
            'event_type' => $this->community->current()->eventTypeKey(),
            'homes' => $this->homes($user),
            'event' => $this->eventView(),
        ]);
    }

    /** Passive probe for UI hints; never extends the idle window. */
    #[Route('/api/me/session', name: 'api_me_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        return new JsonResponse(['session' => $this->sessionInfo($request)]);
    }

    /** Ends every session of this user, on every device, this one included (IdleSessionSubscriber enforces it). */
    #[Route('/api/me/sign-out-everywhere', name: 'api_me_sign_out_everywhere', methods: ['POST'])]
    public function signOutEverywhere(#[CurrentUser] User $user, Security $security): JsonResponse
    {
        $this->users->endSessions($user->id);
        $this->audit->record($user->id, 'user#'.$user->id, 'auth.signed_out_everywhere', 'user', (string) $user->id);
        $security->logout(false);

        return new JsonResponse(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/me/homes', name: 'api_me_homes', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['homes' => $this->homes($user)]);
    }

    #[Route('/api/me/homes', name: 'api_me_homes_claim', methods: ['POST'])]
    public function claim(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $this->throttle->hit('claim_user', 'user:'.$user->id);
        $outcome = $this->claims->claimDirect(
            \is_array($body['address'] ?? null) ? $body['address'] : [],
            \is_array($body['listing'] ?? null) ? $body['listing'] : [],
            $user->id,
            $this->community->current(),
        );

        return match ($outcome->status) {
            'published', 'pending_review' => new JsonResponse(['status' => $outcome->status, 'address_id' => $outcome->addressId, 'address' => $outcome->address], 201),
            'already_yours' => new JsonResponse(['status' => 'already_yours', 'address_id' => $outcome->addressId], 200),
            'already_claimed' => ApiError::response(409, 'already_claimed', 'This home is already claimed.', ['address_id' => $outcome->addressId]),
            'limit' => ApiError::response(409, 'home_limit', (string) $outcome->message),
            'closed' => ApiError::response(403, 'closed', (string) $outcome->message),
            default => ApiError::response(422, 'not_eligible', (string) $outcome->message),
        };
    }

    #[Route('/api/me/homes/{id}', name: 'api_me_home_update', requirements: ['id' => '\d{1,12}'], methods: ['PATCH'])]
    public function update(#[CurrentUser] User $user, Request $request, int $id): JsonResponse
    {
        $this->throttle->hit('listing_user', 'user:'.$user->id); // every save writes an audit record
        $homes = $this->residentHomes->of($user);
        $c = $this->community->current();
        $event = $c->event;
        $row = null === $event ? null : $homes->find($id, $event->id);
        if (null === $row) {
            return null !== $event && $homes->owns($id)
                ? ApiError::response(404, 'not_listed', sprintf('This home is not listed for %s yet. Use “List for %s” to add it.', $c->eventName(), $c->eventName()))
                : ApiError::response(404, 'not_found', 'You do not manage that home.');
        }
        $type = $this->types->for($c);
        $stored = ListingRepository::toDraft($row, $type);
        $draft = ListingDraft::fromInput(JsonBody::decode($request), $c, true, $stored, $this->types);
        // A day-scoped status keeps the day it was set unless it changes now.
        $statusDate = $draft['status'] === $stored['status'] ? ($row['status_date'] ?? null) : ListingDraft::statusDate($draft, $type, $c, $this->clock->now());
        if (!$homes->update($id, $event, $draft, ListingDraft::windowsFor($draft, $event, $type), $statusDate)) {
            return ApiError::response(404, 'not_found', 'You do not manage that home.');
        }
        $this->audit->record($user->id, 'user#'.$user->id, 'listing.updated', 'address', (string) $id, ['status' => $draft['status'], 'published' => $draft['published'], 'event_id' => $event->id]);

        return new JsonResponse(['ok' => true, 'home' => $this->homeFor($user, $id)]);
    }

    /**
     * "List for <event>": lists one of the resident's claimed homes for the active event from the submitted form
     * only. Nothing is copied from an earlier event's listing; the client never names the event.
     */
    #[Route('/api/me/homes/{id}/listing', name: 'api_me_home_list', requirements: ['id' => '\d{1,12}'], methods: ['POST'])]
    public function listForEvent(#[CurrentUser] User $user, Request $request, int $id): JsonResponse
    {
        $this->throttle->hit('listing_user', 'user:'.$user->id);
        $c = $this->community->current();
        $event = $c->event;
        $state = $c->registrationState();
        if (null === $event || !$state['open']) {
            return ApiError::response(403, 'closed', (string) $state['reason']);
        }
        $homes = $this->residentHomes->of($user);
        if (!$homes->owns($id)) {
            return ApiError::response(404, 'not_found', 'You do not manage that home.');
        }
        $type = $this->types->for($c);
        $draft = ListingDraft::fromInput(JsonBody::decode($request), $c, false, null, $this->types);
        $result = $homes->create($id, $event, $draft, ListingDraft::windowsFor($draft, $event, $type), ListingDraft::statusDate($draft, $type, $c, $this->clock->now()));
        if ('created' !== $result) {
            return 'exists' === $result
                ? ApiError::response(409, 'already_listed', sprintf('This home is already listed for %s.', $c->eventName()))
                : ApiError::response(404, 'not_found', 'You do not manage that home.');
        }
        $this->audit->record($user->id, 'user#'.$user->id, 'listing.created', 'address', (string) $id, ['status' => $draft['status'], 'published' => $draft['published'], 'event_id' => $event->id]);

        return new JsonResponse(['ok' => true, 'home' => $this->homeFor($user, $id)], 201);
    }

    #[Route('/api/me/homes/{id}', name: 'api_me_home_release', requirements: ['id' => '\d{1,12}'], methods: ['DELETE'])]
    public function release(#[CurrentUser] User $user, Request $request, int $id): JsonResponse
    {
        if ('RELEASE' !== (JsonBody::decode($request)['confirm'] ?? null)) {
            return ApiError::response(422, 'confirmation_required', 'Type RELEASE to give up management of this home.');
        }

        return 'released' === $this->residentHomes->of($user)->release($id)
            ? new JsonResponse(['ok' => true])
            : ApiError::response(404, 'not_found', 'You do not manage that home.');
    }

    /** @return list<array<string,mixed>> */
    private function homes(User $user): array
    {
        $c = $this->community->current();
        $type = $this->types->for($c);
        $canList = null !== $c->event && $c->registrationState()['open'];

        return array_map(static function (array $r) use ($c, $type, $canList): array {
            $listing = null === ($r['listing_id'] ?? null) ? null : ListingRepository::toDraft($r, $type);

            return [
                'id' => (int) $r['id'],
                'address' => (string) $r['display_address'],
                'zone' => $r['zone_key'],
                'zone_label' => $c->zone($r['zone_key'])['label'] ?? null,
                'inside_boundary' => (bool) $r['inside_boundary'],
                'listing' => null === $listing ? null : $listing + [
                    'event_date' => $listing['windows'][0]['date'] ?? $c->event?->startsOn,
                    'current_season' => true, // only the active event's listing is shown
                    'moderation_hidden' => (bool) $r['moderation_hidden'],
                    'pending_review' => (bool) $r['moderation_hidden'] && ListingRepository::PENDING_REVIEW === $r['moderation_reason'],
                ],
                'can_list' => null === $listing && $canList,
            ];
        }, $this->residentHomes->of($user)->all($c->event?->id));
    }

    /** @return array{id:?int, name:string, type:string, active:bool, starts_on:?string, ends_on:?string, start:string, end:string} the active event, for the forms */
    private function eventView(): array
    {
        $c = $this->community->current();
        $e = $c->event;

        return ['id' => $e?->id, 'name' => $c->eventName(), 'type' => $c->eventTypeKey(), 'active' => null !== $e, 'starts_on' => $e?->startsOn, 'ends_on' => $e?->endsOn,
            'start' => (string) $c->settings['event']['starts_at'], 'end' => (string) $c->settings['event']['ends_at']];
    }

    /** @return array<string,mixed>|null */
    private function homeFor(User $user, int $id): ?array
    {
        foreach ($this->homes($user) as $h) {
            if ($h['id'] === $id) {
                return $h;
            }
        }

        return null;
    }

    /** @return array{idle_minutes:int, expires_in:int} */
    private function sessionInfo(Request $request): array
    {
        $last = (int) $request->getSession()->get(SessionKeys::LAST_ACTIVITY, 0);
        $idle = $this->policy->idleSeconds();

        return ['idle_minutes' => intdiv($idle, 60), 'expires_in' => max(0, $last + $idle - $this->clock->now()->getTimestamp())];
    }
}
