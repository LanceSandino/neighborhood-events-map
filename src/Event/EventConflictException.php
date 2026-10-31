<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/EventConflictException
 * @description An event change that cannot happen in the event's current state (an archived event edited, a draft
 *     already activated, another event activated meanwhile). The organizer API answers 409 with the code and message.
 *
 * @dependencies none
 */
final class EventConflictException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
