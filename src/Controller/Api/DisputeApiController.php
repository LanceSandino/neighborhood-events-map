<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Community\CommunityRepository;
use App\Dispute\DisputeService;
use App\Security\ClientIdentity;
use App\Security\EmailNormalizer;
use App\Security\ProofOfWork;
use App\Security\ResponseFloorSubscriber;
use App\Security\Throttle;
use App\Shared\JsonBody;
use App\Shared\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @module Controller/Api/DisputeApiController
 * @description POST /api/disputes — PoW, response-time floor, honeypot, per-IP throttle, then (only for
 *     valid requests) per-home and global daily caps; stores a review request and emails ADMIN_EMAIL.
 *     Returns a ticket number; never discloses the current owner.
 */
final class DisputeApiController
{
    #[Route('/api/disputes', name: 'api_disputes', methods: ['POST'])]
    public function create(Request $request, ProofOfWork $pow, Throttle $throttle, ClientIdentity $client, EmailNormalizer $emails, DisputeService $disputes, CommunityRepository $community): JsonResponse
    {
        $body = JsonBody::decode($request);
        $p = \is_array($body['pow'] ?? null) ? $body['pow'] : [];
        $pow->verify('dispute', $p['id'] ?? null, $p['nonce'] ?? null, $client->ipHash($request));
        ResponseFloorSubscriber::mark($request); // the caller paid proof-of-work; every outcome now takes the same time
        $email = $emails->normalize((string) ($body['reporter_email'] ?? ''));
        if (null === $email) {
            throw new ValidationException(['reporter_email' => 'Enter a valid email address so the organizer can reply.']);
        }
        $throttle->hit('dispute_ip', $client->ip($request));
        if ('' !== trim((string) ($body['website'] ?? ''))) {
            // Honeypot field filled in: answer like a success, store and send nothing.
            return self::accepted(DisputeService::ticket(random_int(100_000, 999_999)));
        }
        $addressId = (int) ($body['address_id'] ?? 0);
        $house = \is_scalar($body['house_number'] ?? null) ? (string) $body['house_number'] : '';
        $disputes->validate($addressId, (string) ($body['reason'] ?? ''), $community->current(), $house);
        // Per-home and global budgets are spent only by valid requests (no blocking a home with junk).
        $throttle->hit('dispute_address', 'address:'.$addressId);
        $throttle->hit('dispute_global', 'global');
        $result = $disputes->open($addressId, $email, (string) ($body['reason'] ?? ''), $community->current(), $house);

        return self::accepted($result['ticket']);
    }

    private static function accepted(string $ticket): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'ticket' => $ticket,
            'message' => sprintf('Thanks. Your review request %s was sent to the organizer, who will follow up by email. Nothing changes until they review it.', $ticket),
        ], 201, ['Cache-Control' => 'no-store']);
    }
}
