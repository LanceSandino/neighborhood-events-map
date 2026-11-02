<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/NextEvent
 * @description Next year's event of one that closes (D-20261031-39, D-20261101-01): the same type, name (a year in it
 *     moves on), default hours and lasting settings, the type's tentative dates (or dates to be announced), never the
 *     date-bound settings (best night, sale days) or the thank-you text. It is created hidden: residents get ready in
 *     My house, visitors see nothing until the organizer announces or opens it. Also what the organizer's close
 *     confirmation shows: the thank-you text visitors see meanwhile (it names no hidden event) and next year's dates.
 *
 * @dependencies Event/EventOccurrence, Event/EventType, Event/EventSettingsType, Event/EventTypeRegistry, Event/ClosingMessage, Event/EventDates
 */
final class NextEvent
{
    public function __construct(private readonly EventTypeRegistry $types)
    {
    }

    /**
     * The fields of next year's event of a closing one.
     *
     * @return array{type:string,name:string,starts_on:?string,ends_on:?string,dates_tentative:bool,default_start:string,default_end:string,settings:array<string,mixed>}
     */
    public function fields(EventOccurrence $closed): array
    {
        $type = $this->types->get($closed->type);
        [$first, $last] = $type->nextDates($closed);
        $settings = $closed->settings;
        unset($settings['closing_message']);
        if ($type instanceof EventSettingsType) {
            foreach ($type->dateBoundSettings() as $key) {
                unset($settings[$key]);
            }
            if (null !== $first && null !== $last) {
                $settings = $type->finishSettings($settings, $first, $last)[0];
            }
        }
        $year = EventDates::year($closed);
        $nextYear = null !== $first ? substr($first, 0, 4) : (string) ((int) $year + 1);
        $name = '' !== $year && str_contains($closed->name, $year) ? str_replace($year, $nextYear, $closed->name) : $closed->name;

        return ['type' => $closed->type, 'name' => mb_substr($name, 0, 60), 'starts_on' => $first, 'ends_on' => $last, 'dates_tentative' => null !== $first,
            'default_start' => $closed->defaultStart, 'default_end' => $closed->defaultEnd, 'settings' => $settings];
    }

    /**
     * What closing an open event will do, for the organizer's confirmation: the thank-you text visitors see while next
     * year's event is hidden, and next year's dates.
     *
     * @return array{message: string, next: array{name: string, starts_on: ?string, ends_on: ?string, dates_tentative: bool, words: string, state: string}}
     */
    public function preview(EventOccurrence $e): array
    {
        $next = $this->fields($e);
        $hidden = new EventOccurrence(0, $e->type, $next['name'], $next['starts_on'], $next['ends_on'], $next['default_start'], $next['default_end'], [],
            EventOccurrence::HIDDEN, datesTentative: $next['dates_tentative']);

        return ['message' => ClosingMessage::text($e, $this->types->get($e->type), null),
            'next' => ['name' => $next['name'], 'starts_on' => $next['starts_on'], 'ends_on' => $next['ends_on'], 'dates_tentative' => $next['dates_tentative'],
                'words' => EventDates::words($hidden), 'state' => EventOccurrence::HIDDEN]];
    }
}
