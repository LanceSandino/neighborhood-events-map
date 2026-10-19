<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * @module Geo/GeometryException
 * @description Raised when submitted GeoJSON fails validation; carries every problem found.
 */
final class GeometryException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
