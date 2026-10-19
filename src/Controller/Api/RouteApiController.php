<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Community\CommunityRepository;
use App\Routing\RoutePlanner;
use App\Routing\RoutingUnavailableException;
use App\Security\ClientIdentity;
use App\Security\Throttle;
use App\Shared\ApiError;
use App\Shared\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @module Controller/Api/RouteApiController
 * @description POST /api/routes/plan — optional provider walking path for one trip (throttled).
 */
final class RouteApiController
{
    #[Route('/api/routes/plan', name: 'api_route_plan', methods: ['POST'])]
    public function plan(Request $request, RoutePlanner $planner, CommunityRepository $community, Throttle $throttle, ClientIdentity $client): JsonResponse
    {
        $throttle->hit('route_ip', $client->ip($request));
        $stops = JsonBody::decode($request)['stops'] ?? [];
        try {
            return new JsonResponse(['route' => $planner->plan(\is_array($stops) ? $stops : [], $community->current())]);
        } catch (RoutingUnavailableException $e) {
            return ApiError::response(409, 'routing_unavailable', $e->getMessage());
        }
    }
}
