<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\EventType;
use App\Event\OccurrenceSettings;
use App\Event\Type\HalloweenType;

/**
 * @module tests/Support/TestEventType
 * @description Test double for core code paths no enabled type reaches yet (Phase 2 enables only Halloween): a
 *   reserved key with another schedule kind and route profiles, everything else delegated to HalloweenType. Never
 *   registered in the container.
 * @dependencies Event/EventType, Event/Type/HalloweenType
 */
final class TestEventType implements EventType
{
    private readonly HalloweenType $halloween;

    /** @param list<string> $profiles */
    public function __construct(private readonly string $key = 'holiday_lights', private readonly string $kind = 'nightly_range', private readonly array $profiles = ['walking', 'driving'])
    {
        $this->halloween = new HalloweenType();
    }

    public function key(): string
    {
        return $this->key;
    }

    public function defaultName(): string
    {
        return 'Test Lights';
    }

    public function defaultBranding(): array
    {
        return $this->halloween->defaultBranding();
    }

    public function copy(): array
    {
        return $this->halloween->copy();
    }

    public function scheduleKind(): string
    {
        return $this->kind;
    }

    public function maxDays(): int
    {
        return ['single_day' => 1, 'nightly_range' => 62, 'sale_days' => 3][$this->kind];
    }

    public function hourChoices(): array
    {
        return $this->halloween->hourChoices();
    }

    public function statuses(): array
    {
        return $this->halloween->statuses();
    }

    public function displayRules(): array
    {
        return $this->halloween->displayRules();
    }

    public function features(): array
    {
        return $this->halloween->features();
    }

    public function defaultDetails(): array
    {
        return $this->halloween->defaultDetails();
    }

    public function normalizeDetails(array $input, OccurrenceSettings $settings): array
    {
        return $this->halloween->normalizeDetails($input, $settings);
    }

    public function publicDetails(array $details): array
    {
        return $this->halloween->publicDetails($details);
    }

    public function legacyFields(array $details): array
    {
        return [];
    }

    public function filters(): array
    {
        return $this->halloween->filters();
    }

    public function legend(): array
    {
        return $this->halloween->legend();
    }

    public function stats(array $homes): array
    {
        return [];
    }

    public function routeProfiles(): array
    {
        return $this->profiles;
    }

    public function fragments(): array
    {
        return [];
    }
}
