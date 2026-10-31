<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/EventOccurrence
 * @description One event of the community (a row of event_occurrences): its immutable type key, public name, date
 *     range in the community's local calendar, default hours, validated type settings and life-cycle state
 *     (draft → active → archived; one active at a time). Listings belong to exactly one occurrence.
 *
 * @dependencies none
 */
final class EventOccurrence
{
    public const DRAFT = 'draft';
    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';

    /** @param array<string,mixed> $settings */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $defaultStart,
        public readonly string $defaultEnd,
        public readonly array $settings,
        public readonly string $state,
        public readonly ?string $createdAt = null,
        public readonly ?string $activatedAt = null,
        public readonly ?string $archivedAt = null,
    ) {
    }

    /** @param array<string,mixed> $r an event_occurrences row */
    public static function fromRow(array $r): self
    {
        $settings = \is_string($r['settings'] ?? null) ? json_decode($r['settings'], true, 32, \JSON_THROW_ON_ERROR) : ($r['settings'] ?? []);

        return new self((int) $r['id'], (string) $r['type'], (string) $r['name'], (string) $r['starts_on'], (string) $r['ends_on'],
            substr((string) $r['default_start_local'], 0, 5), substr((string) $r['default_end_local'], 0, 5), \is_array($settings) ? $settings : [],
            (string) $r['state'], isset($r['created_at']) ? (string) $r['created_at'] : null,
            isset($r['activated_at']) ? (string) $r['activated_at'] : null, isset($r['archived_at']) ? (string) $r['archived_at'] : null);
    }

    public function isActive(): bool
    {
        return self::ACTIVE === $this->state;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->startsOn && $date <= $this->endsOn;
    }

    /** @return list<string> every local date of the event, first to last */
    public function dates(): array
    {
        $out = [];
        $d = new \DateTimeImmutable($this->startsOn);
        $end = new \DateTimeImmutable($this->endsOn);
        while ($d <= $end) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }

        return $out;
    }

    /**
     * The organizer's view of the event (dates as local dates, hours as HH:MM).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'name' => $this->name, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn,
            'default_start' => $this->defaultStart, 'default_end' => $this->defaultEnd, 'settings' => $this->settings, 'state' => $this->state,
            'created_at' => $this->createdAt, 'activated_at' => $this->activatedAt, 'archived_at' => $this->archivedAt];
    }
}
