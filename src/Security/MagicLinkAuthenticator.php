<?php

declare(strict_types=1);

namespace App\Security;

use App\Claim\ClaimOutcome;
use App\Claim\ClaimRejectedException;
use App\Claim\ClaimService;
use App\Community\CommunityRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Twig\Environment;

/**
 * @module Security/MagicLinkAuthenticator
 * @description Consumes a one-time email link ONLY on an explicit, CSRF-protected POST to
 *     /auth/confirm (GET merely renders a confirmation page, so link scanners cannot burn it).
 *     On success: marks the email verified, completes any pending claim atomically, rotates the
 *     session (fixation strategy "invalidate") and starts the server-side idle clock.
 *
 * @dependencies Security/MagicLinkTokens, Security/UserRepository, Claim/ClaimService
 */
final class MagicLinkAuthenticator extends AbstractAuthenticator
{
    public const CSRF_ID = 'magic_confirm';

    public function __construct(
        private readonly MagicLinkTokens $tokens,
        private readonly UserRepository $users,
        private readonly ClaimService $claims,
        private readonly CommunityRepository $community,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly Environment $twig,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && '/auth/confirm' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_csrf')))) {
            throw new CustomUserMessageAuthenticationException('This confirmation page expired. Open the link from your email again.');
        }
        $consumed = $this->tokens->consume((string) $request->request->get('t'));
        if (null === $consumed) {
            throw new CustomUserMessageAuthenticationException('This sign-in link has expired or was already used.');
        }
        $user = $this->users->upsertVerified($consumed['email']);
        $outcome = null;
        if (null !== $consumed['intent_id']) {
            try {
                $outcome = $this->claims->completeIntent((string) $consumed['intent_id'], $user->id, $user->email, $this->community->current());
            } catch (ClaimRejectedException $e) {
                $outcome = new ClaimOutcome('already_claimed' === $e->reason ? 'already_claimed' : 'rejected', $e->addressId, null, $e->getMessage());
            }
        }
        $request->attributes->set('_nhm_claim_outcome', $outcome);

        return new SelfValidatingPassport(new UserBadge($user->email, static fn () => $user));
    }

    /** Session attributes are written by LoginSessionSubscriber, after the session is rotated. */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $next = (string) $request->request->get('next', '');
        $target = preg_match('~^/(me|admin)(/[A-Za-z0-9/_-]*)?\z~', $next) ? $next : '/me';

        return new RedirectResponse($target, Response::HTTP_SEE_OTHER);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response($this->twig->render('auth/link_problem.html.twig', [
            'message' => $exception->getMessageKey(),
        ]), Response::HTTP_GONE, ['Cache-Control' => 'no-store']);
    }
}
