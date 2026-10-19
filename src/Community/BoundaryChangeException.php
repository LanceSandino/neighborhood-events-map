<?php

declare(strict_types=1);

namespace App\Community;

/**
 * @module Community/BoundaryChangeException
 * @description A new boundary would leave existing claims outside it; the organizer must confirm.
 */
final class BoundaryChangeException extends \RuntimeException
{
    public function __construct(public readonly int $outsideClaims)
    {
        parent::__construct(sprintf('%d claimed home(s) would fall outside the new boundary. Confirm to continue; their listings will be hidden until corrected.', $outsideClaims));
    }
}
