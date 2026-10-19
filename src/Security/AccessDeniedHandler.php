<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * @module Security/AccessDeniedHandler
 * @description Signed in but lacking the role (e.g. a resident on /admin): 403, never executes.
 */
final class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return ApiError::response(403, 'forbidden', 'You do not have permission for that.');
        }

        return new Response('<!doctype html><title>Forbidden</title><p>You do not have permission to view this page. <a href="/">Back to the map</a></p>', 403, ['Cache-Control' => 'no-store']);
    }
}
