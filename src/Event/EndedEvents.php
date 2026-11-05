<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunityRepository;

/**
 * @module Event/EndedEvents
 * @description Events close by themselves after their last day (D-20261104-01): one run closes every open event whose
 *     last day is before today in the community's time zone, on the app's clock, the oldest first, each through
 *     Event/EventLifecycle in its own transaction, so a failure leaves the others to close and the next run tries it
 *     again. Hidden, upcoming and draft events are never touched. An event of a type this release does not offer is left
 *     for the organizer to archive. A closed default an earlier release left behind moves on (Event/DefaultEventRule).
 *     Nothing happens twice: a run that finds nothing to do changes nothing. App start and the maintenance loop run it
 *     (Command/EventsCloseEndedCommand).
 *
 * @dependencies Event/EventOccurrenceRepository, Event/EventLifecycle, Community/CommunityRepository
 */
final class EndedEvents
{
    public function __construct(
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventLifecycle $lifecycle,
        private readonly CommunityRepository $community,
    ) {
    }

    /** @return array{closed: list<array{id:int,name:string,last_day:string,next_id:int}>, skipped: list<array{id:int,name:string,reason:string}>, failed: list<array{id:?int,name:string,error:string}>} */
    public function run(): array
    {
        $today = $this->community->localToday();
        $out = ['closed' => [], 'skipped' => [], 'failed' => []];
        foreach ($this->occurrences->openEndedBefore($today) as $event) {
            try {
                $closed = $this->lifecycle->closeEnded($event->id, $today);
                if (null !== $closed) {
                    $out['closed'][] = ['id' => $event->id, 'name' => $event->name, 'last_day' => $event->lastDay(), 'next_id' => $closed['next']->id];
                }
            } catch (EventConflictException $e) {
                $out['skipped'][] = ['id' => $event->id, 'name' => $event->name, 'reason' => $e->reason];
            } catch (\Throwable $e) {
                $out['failed'][] = ['id' => $event->id, 'name' => $event->name, 'error' => $e->getMessage()];
            }
        }
        try {
            $this->lifecycle->settleDefault();
        } catch (\Throwable $e) {
            $out['failed'][] = ['id' => null, 'name' => 'the default event', 'error' => $e->getMessage()];
        }

        return $out;
    }
}
