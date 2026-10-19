<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @module Shared/ApiError
 * @description Consistent JSON error envelope: {"error": {"code", "message", "fields"?}}.
 */
final class ApiError
{
    /** @param array<string,mixed> $extra */
    public static function response(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        $resp = new JsonResponse(['error' => ['code' => $code, 'message' => $message] + $extra], $status, $headers);
        $resp->headers->set('Cache-Control', 'no-store');

        return $resp;
    }
}
