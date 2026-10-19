<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Audit\AuditLog;
use App\Claim\ClaimService;
use App\Community\CommunityRepository;
use App\Listing\ListingDraft;
use App\Listing\ListingRepository;
use App\Listing\ResidentHomes;
use App\Security\SessionKeys;
use App\Security\SessionPolicy;
use App\Security\Throttle;
use App\Security\User;
use App\Shared\ApiError;
use App\Shared\JsonBody;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/Api/MeApiController
 * @description Owner-only API (ROLE_USER, declared here and in access_control; CSRF on writes; idle
 *     timeout enforced before this runs). A resident's own homes are reached only through the
 *     owner-scoped ResidentHomes, so no query here can touch another resident's home.
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
            'homes' => $this->homes($user),
        ]);
    }

    /** Passive probe for UI hints; never extends the idle window. */
    #[Route('/api/me/session', name: 'api_me_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        return new JsonResponse(['session' => $this->sessionInfo($request)]);
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
        $homes = $this->residentHomes->of($user);
        $row = $homes->find($id);
        if (null === $row) {
            return ApiError::response(404, 'not_found', 'You do not manage that home.');
        }
        $c = $this->community->current();
        $draft = ListingDraft::fromInput(JsonBody::decode($request), $c, true, ListingRepository::toDraft($row));
        // Saving opts the home into the current event date (seasonal re-opt-in).
        if (!$homes->update($id, $c->eventDate(), $draft)) {
            return ApiError::response(404, 'not_found', 'You do not manage that home.');
        }
        $this->audit->record($user->id, 'user#'.$user->id, 'listing.updated', 'address', (string) $id, ['status' => $draft['status'], 'published' => $draft['published']]);

        return new JsonResponse(['ok' => true, 'home' => $this->homeFor($user, $id)]);
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

        return array_map(static function (array $r) use ($c): array {
            $hasListing = null !== ($r['address_id'] ?? null);

            return [
                'id' => (int) $r['id'],
                'address' => (string) $r['display_address'],
                'zone' => $r['zone_key'],
                'zone_label' => $c->zone($r['zone_key'])['label'] ?? null,
                'inside_boundary' => (bool) $r['inside_boundary'],
                'listing' => $hasListing ? ListingRepository::toDraft($r) + [
                    'event_date' => $r['event_date'],
                    'current_season' => $r['event_date'] === $c->eventDate(),
                    'moderation_hidden' => (bool) $r['moderation_hidden'],
                    'pending_review' => (bool) $r['moderation_hidden'] && ListingRepository::PENDING_REVIEW === $r['moderation_reason'],
                ] : null,
            ];
        }, $this->residentHomes->of($user)->all());
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
