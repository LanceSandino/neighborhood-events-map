<?php

declare(strict_types=1);

namespace App\Controller;

use App\Claim\ClaimService;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use App\Security\MagicLinkAuthenticator;
use App\Security\MagicLinkTokens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @module Controller/AuthPageController
 * @description GET /auth/confirm renders "Confirm sign-in (and publish)" WITHOUT consuming the link.
 *     The POST is handled entirely by MagicLinkAuthenticator.
 */
final class AuthPageController extends AbstractController
{
    #[Route('/auth/confirm', name: 'auth_confirm', methods: ['GET'])]
    public function confirm(Request $request, MagicLinkTokens $tokens, ClaimService $claims, CommunityRepository $community, CommunityPresenter $presenter): Response
    {
        $raw = (string) $request->query->get('t', '');
        $peek = $tokens->peek($raw);
        $summary = null !== $peek && null !== $peek['intent_id'] ? $claims->intentSummary((string) $peek['intent_id']) : null;

        return $this->render('auth/confirm.html.twig', [
            'c' => $presenter->publicPayload($community->current(), []),
            'token' => null !== $peek && 'valid' === $peek['state'] ? $raw : null,
            'state' => $peek['state'] ?? 'invalid',
            'claim' => $summary,
            'csrf_id' => MagicLinkAuthenticator::CSRF_ID,
        ], new Response(status: null !== $peek && 'valid' === $peek['state'] ? 200 : 410));
    }

    #[Route('/auth/confirm', name: 'auth_confirm_post', methods: ['POST'])]
    public function confirmPost(): Response
    {
        throw new \LogicException('Handled by MagicLinkAuthenticator.');
    }
}
