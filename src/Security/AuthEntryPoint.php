<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\ApiError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * @module Security/AuthEntryPoint
 * @description Unauthenticated access to protected areas: JSON 401 for APIs, redirect to the
 *     passwordless sign-in page (preserving a safe destination) for pages.
 */
final class AuthEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return ApiError::response(401, 'unauthenticated', 'Please sign in with an email link.');
        }

        return new RedirectResponse('/login?next='.rawurlencode($request->getPathInfo()));
    }
}
