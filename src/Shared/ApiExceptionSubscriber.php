<?php

declare(strict_types=1);

namespace App\Shared;

use App\Claim\ClaimRejectedException;
use App\Community\BoundaryChangeException;
use App\Community\ConfigException;
use App\Geo\GeometryException;
use App\Security\PowException;
use App\Security\RateLimitedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * @module Shared/ApiExceptionSubscriber
 * @description Maps domain exceptions on /api/* to stable JSON errors: 400 PoW, 401/403 auth,
 *     404, 409 already claimed / boundary confirmation, 422 validation, 429 throttled (Retry-After).
 *     Unexpected errors return a generic 500 without internals.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof AccessDeniedException || $e instanceof AuthenticationException) {
            return; // the firewall's ExceptionListener turns these into 401/403 via the entry point
        }
        $response = match (true) {
            $e instanceof ValidationException => ApiError::response(422, 'validation_failed', $e->getMessage(), ['fields' => $e->fields]),
            $e instanceof ClaimRejectedException => ApiError::response($e->httpStatus(), $e->reason, $e->getMessage(),
                null !== $e->addressId && 'already_claimed' === $e->reason ? ['address_id' => $e->addressId] : []),
            $e instanceof RateLimitedException => ApiError::response(429, 'rate_limited', 'Too many requests. Please wait a bit and try again.',
                ['retry_after' => $e->retryAfter], ['Retry-After' => (string) $e->retryAfter]),
            $e instanceof PowException => ApiError::response(400, 'pow_'.$e->code_, $e->getMessage()),
            $e instanceof GeometryException => ApiError::response(422, 'invalid_geometry', 'The GeoJSON is not valid.', ['problems' => $e->errors]),
            $e instanceof ConfigException => ApiError::response(422, 'invalid_config', 'The configuration is not valid.', ['problems' => $e->errors]),
            $e instanceof BoundaryChangeException => ApiError::response(409, 'boundary_confirmation_required', $e->getMessage(), ['outside_claims' => $e->outsideClaims]),
            $e instanceof \JsonException => ApiError::response(400, 'bad_json', 'Request body must be valid JSON.'),
            $e instanceof HttpExceptionInterface => ApiError::response($e->getStatusCode(), match ($e->getStatusCode()) {
                404 => 'not_found', 405 => 'method_not_allowed', 403 => 'forbidden', 401 => 'unauthenticated', default => 'http_error',
            }, $e->getStatusCode() < 500 ? ($e->getMessage() ?: 'Request failed.') : 'Server error.', [], $e->getHeaders()),
            default => null,
        };
        if (null === $response) {
            $this->logger->error('Unhandled API exception', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $response = ApiError::response(500, 'server_error', 'Something went wrong. Please try again.');
        }
        $event->setResponse($response);
    }
}
