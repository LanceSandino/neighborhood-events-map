<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunityConfig;

/**
 * @module Event/OccurrenceSettings
 * @description The settings of the event a listing belongs to, as an event type's validation sees them. Until
 *     events have their own rows (event_occurrences), they come from the community: its feature switches (an
 *     offering the organizer turned off is forced off) and no type-specific settings yet.
 *
 * @dependencies Community/CommunityConfig
 */
final class OccurrenceSettings
{
    /**
     * @param array<string,bool>  $features community feature switches
     * @param array<string,mixed> $settings validated type-specific event settings
     */
    public function __construct(public readonly array $features = [], public readonly array $settings = [])
    {
    }

    public static function fromCommunity(CommunityConfig $community): self
    {
        return new self($community->features());
    }

    /** A switch the settings do not mention is on (new switches default to the behaviour before they existed). */
    public function feature(string $key): bool
    {
        return (bool) ($this->features[$key] ?? true);
    }
}
