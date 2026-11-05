<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/EventOccurrence
 * @description One event of the community (a row of event_occurrences): its immutable type key, public name, date
 *     range in the community's local calendar (both null while the dates are to be announced; tentative dates are
 *     flagged), default hours, validated type settings (with the organizer's closing message), its life-cycle state —
 *     draft (organizers only) → hidden (residents get ready in My house; visitors see nothing, D-20261101-01) →
 *     upcoming (residents may list; visitors see the dates) → open (the public map with homes) → closed (a thank-you
 *     message) → archived (gone) — and whether it is the default event "/" shows (D-20261031-37). Listings belong to
 *     exactly one occurrence.
 *
 * @dependencies none
 */
final class EventOccurrence
{
    public const DRAFT = 'draft';
    /** Residents may list in My house; visitors never see it (not public). Closing an event creates next year's in it. */
    public const HIDDEN = 'hidden';
    public const UPCOMING = 'upcoming';
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const ARCHIVED = 'archived';
    /** Visitors see these events (the switcher, /api/events); the others are the organizer's only. */
    public const PUBLIC_STATES = [self::UPCOMING, self::OPEN, self::CLOSED];
    /** Residents see these in My house: the public ones and hidden ones they can get ready for. */
    public const RESIDENT_STATES = [self::HIDDEN, self::UPCOMING, self::OPEN, self::CLOSED];
    /** At most one event of a type is current at a time (a partial unique index); a hidden one counts. */
    public const CURRENT_STATES = [self::HIDDEN, self::UPCOMING, self::OPEN];
    public const CLOSING_MESSAGE_MAX = 400;

    /** @param array<string,mixed> $settings */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $startsOn,
        public readonly ?string $endsOn,
        public readonly string $defaultStart,
        public readonly string $defaultEnd,
        public readonly array $settings,
        public readonly string $state,
        public readonly ?string $createdAt = null,
        public readonly ?string $activatedAt = null,
        public readonly ?string $archivedAt = null,
        public readonly bool $isDefault = false,
        public readonly bool $datesTentative = false,
        public readonly ?string $closedAt = null,
    ) {
    }

    /** @param array<string,mixed> $r an event_occurrences row */
    public static function fromRow(array $r): self
    {
        $settings = \is_string($r['settings'] ?? null) ? json_decode($r['settings'], true, 32, \JSON_THROW_ON_ERROR) : ($r['settings'] ?? []);

        return new self((int) $r['id'], (string) $r['type'], (string) $r['name'], self::date($r['starts_on'] ?? null), self::date($r['ends_on'] ?? null),
            substr((string) $r['default_start_local'], 0, 5), substr((string) $r['default_end_local'], 0, 5), \is_array($settings) ? $settings : [],
            (string) $r['state'], isset($r['created_at']) ? (string) $r['created_at'] : null,
            isset($r['activated_at']) ? (string) $r['activated_at'] : null, isset($r['archived_at']) ? (string) $r['archived_at'] : null,
            (bool) ($r['is_default'] ?? false), (bool) ($r['dates_tentative'] ?? false), isset($r['closed_at']) ? (string) $r['closed_at'] : null);
    }

    /** The event is open: the public map shows its homes. */
    public function isOpen(): bool
    {
        return self::OPEN === $this->state;
    }

    public function isPublic(): bool
    {
        return \in_array($this->state, self::PUBLIC_STATES, true);
    }

    /** Visitors see it as a current event: a tab of the switcher, and it may be the default (not closed, not hidden). */
    public function isOnSiteNow(): bool
    {
        return self::UPCOMING === $this->state || self::OPEN === $this->state;
    }

    /** Hidden from visitors; residents get ready for it in My house. */
    public function isHidden(): bool
    {
        return self::HIDDEN === $this->state;
    }

    /** Residents see it in My house (a public event or a hidden one). */
    public function isForResidents(): bool
    {
        return \in_array($this->state, self::RESIDENT_STATES, true);
    }

    /** Dates are set (possibly tentative); without them the dates are to be announced. */
    public function hasDates(): bool
    {
        return null !== $this->startsOn && null !== $this->endsOn;
    }

    /** Residents may list for a hidden, upcoming or open event that has dates (a listing's days lie inside them). */
    public function acceptsListings(): bool
    {
        return \in_array($this->state, self::CURRENT_STATES, true) && $this->hasDates();
    }

    /** The first day; only for an event with dates (every event that accepts listings has them). */
    public function firstDay(): string
    {
        return $this->startsOn ?? throw new \LogicException('This event has no dates yet (to be announced).');
    }

    /** The last day; only for an event with dates. */
    public function lastDay(): string
    {
        return $this->endsOn ?? throw new \LogicException('This event has no dates yet (to be announced).');
    }

    public function contains(string $date): bool
    {
        return $this->hasDates() && $date >= $this->startsOn && $date <= $this->endsOn;
    }

    /** @return list<string> every local date of the event, first to last (none while to be announced) */
    public function dates(): array
    {
        if (!$this->hasDates()) {
            return [];
        }
        $out = [];
        $d = new \DateTimeImmutable($this->firstDay());
        $end = new \DateTimeImmutable($this->lastDay());
        while ($d <= $end) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }

        return $out;
    }

    /** The organizer's own thank-you text for a closed event ('' when the type's default applies). */
    public function closingMessage(): string
    {
        return \is_string($this->settings['closing_message'] ?? null) ? $this->settings['closing_message'] : '';
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
            'created_at' => $this->createdAt, 'activated_at' => $this->activatedAt, 'archived_at' => $this->archivedAt,
            'is_default' => $this->isDefault, 'dates_tentative' => $this->datesTentative, 'closed_at' => $this->closedAt];
    }

    private static function date(mixed $v): ?string
    {
        return null === $v ? null : (string) $v;
    }
}
