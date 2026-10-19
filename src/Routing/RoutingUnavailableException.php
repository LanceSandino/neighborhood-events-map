<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * @module Routing/RoutingUnavailableException
 * @description No safe provider route can be offered (not configured, crossings unverified, provider error).
 */
final class RoutingUnavailableException extends \RuntimeException
{
}
