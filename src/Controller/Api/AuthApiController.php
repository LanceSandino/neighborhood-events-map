<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Claim\ClaimService;
use App\Community\CommunityRepository;
use App\Mail\Notifier;
use App\Security\ClientIdentity;
use App\Security\EmailNormalizer;
use App\Security\MagicLinkTokens;
use App\Security\ProofOfWork;
use App\Security\ResponseFloorSubscriber;
use App\Security\SessionPolicy;
use App\Security\Throttle;
use App\Security\UserRepository;
use App\Shared\ApiError;
use App\Shared\JsonBody;
use App\Shared\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @module Controller/Api/AuthApiController
 * @description Passwordless sign-in: POST /api/auth/request-link (PoW, response-time floor, honeypot,
 *     persistent throttles spent only by mail that is sent, optional claim intent, neutral response,
 *     15-minute one-time link by email) and POST /api/auth/logout (server-side session revocation).
 */
final class AuthApiController
{
    public function __construct(
        private readonly ProofOfWork $pow,
        private readonly Throttle $throttle,
        private readonly ClientIdentity $client,
        private readonly EmailNormalizer $emails,
        private readonly MagicLinkTokens $tokens,
        private readonly ClaimService $claims,
        private readonly CommunityRepository $community,
        private readonly SessionPolicy $policy,
        private readonly Notifier $notifier,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/auth/request-link', name: 'api_request_link', methods: ['POST'])]
    public function requestLink(Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $claim = \is_array($body['claim'] ?? null) ? $body['claim'] : null;
        $purpose = null !== $claim ? 'claim' : 'login';
        $pow = \is_array($body['pow'] ?? null) ? $body['pow'] : [];
        $this->pow->verify($purpose, $pow['id'] ?? null, $pow['nonce'] ?? null, $this->client->ipHash($request));
        // From here on every outcome takes at least the floor time (the caller paid proof-of-work).
        ResponseFloorSubscriber::mark($request);

        $email = $this->emails->normalize((string) ($body['email'] ?? ''));
        if (null === $email) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }
        $this->throttle->hit('link_ip', $this->client->ip($request));
        $minutes = $this->policy->linkMinutes();
        if ('' !== trim((string) ($body['website'] ?? ''))) {
            // Honeypot field filled in (invisible to people): answer exactly like a success, do nothing.
            $this->logger->notice('Honeypot submission ignored', ['purpose' => $purpose]);

            return self::accepted($email, $minutes, null);
        }

        $community = $this->community->current();
        $intentId = null;
        $echo = null;
        $prepared = null;
        if (null !== $claim) {
            // Eligibility first: requests that cannot lead to an email spend no per-email or global budget.
            $prepared = $this->claims->prepareIntent($email, \is_array($claim['address'] ?? null) ? $claim['address'] : [],
                \is_array($claim['listing'] ?? null) ? $claim['listing'] : [], $community, $minutes);
            $this->throttle->hit('link_address', 'address:'.$prepared['address_key']);
            $intentId = $prepared['intent_id'];
            // Echo only what the caller typed (self-entry); registry text is never returned for an id.
            $echo = 'self_entry' === $prepared['source'] ? $prepared['address'] : null;
        }
        $this->throttle->hit('link_email', $this->emails->throttleKey($email));
        if (!$this->users->isOrganizerEmail($email)) {
            $this->throttle->hit('link_global', 'global'); // organizers can always sign in
        }

        $raw = $this->tokens->issue($email, $purpose, $intentId, $minutes);
        try {
            $this->notifier->sendMagicLink($email, $raw, $minutes, $community, $prepared['address'] ?? null);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Magic link email failed', ['to' => EmailNormalizer::mask($email), 'error' => $e->getMessage()]);

            return ApiError::response(503, 'mail_unavailable', 'We could not send email right now. Please try again in a few minutes.');
        }
        $this->logger->info('Magic link issued', ['to' => EmailNormalizer::mask($email), 'purpose' => $purpose]);

        return self::accepted($email, $minutes, $echo);
    }

    private static function accepted(string $email, int $minutes, ?string $address): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'message' => sprintf('If %s can receive email, a sign-in link is on its way. It works once and expires in %d minutes.', $email, $minutes),
            'minutes' => $minutes,
            'address' => $address,
        ], 202, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/auth/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(Request $request, Security $security): JsonResponse
    {
        if (null !== $security->getUser()) {
            $security->logout(false);
        } elseif ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new JsonResponse(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
