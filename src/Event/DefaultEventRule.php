<?php

declare(strict_types=1);

namespace App\Event;

use App\Audit\AuditLog;
use App\Community\CommunityRepository;
use Doctrine\DBAL\Connection;

/**
 * @module Event/DefaultEventRule
 * @description Which event "/" shows when the default stops being a current one (D-20261104-03): the current event
 *     happening soonest — an open event whose dates include today in the community's time zone, then the earliest
 *     starting open event, then the earliest announced upcoming one (dates to be announced last), of types this release
 *     offers. With none, a closed default stays on its thank-you page (a default that was archived or hidden passes to the
 *     newest public event). A default that is upcoming or open is never touched, so an organizer's "Make default" holds
 *     while that event stays current. A hidden event is never it. Runs inside the life-cycle transaction, under the
 *     occurrences lock, and audits each automatic move in that transaction.
 *
 * @dependencies Event/EventOccurrenceRepository, Event/EventTypeRegistry, Community/CommunityRepository, Audit/AuditLog, DBAL
 */
final class DefaultEventRule
{
    public function __construct(
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventTypeRegistry $types,
        private readonly CommunityRepository $community,
        private readonly AuditLog $audit,
    ) {
    }

    /** The current event happening soonest, leaving one out (the event about to close); null when none is current. */
    public function soonest(Connection $db, ?int $except = null): ?EventOccurrence
    {
        foreach ($this->occurrences->currentBySoonest($this->community->localToday(), $db) as $event) {
            if ($event->id !== $except && $this->types->isEnabled($event->type)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Under the lock, after a change: moves a default that is no longer current.
     *
     * @param int|null $wasDefault the event that was the default before the change (it may have lost the flag)
     *
     * @return bool whether the default moved
     */
    public function settle(Connection $db, ?int $adminId, ?int $wasDefault = null): bool
    {
        $flagged = $this->occurrences->flaggedDefault($db);
        if (null !== $flagged && EventOccurrence::CLOSED !== $flagged->state) {
            return false; // upcoming or open: still current (the check allows nothing else but closed)
        }
        $next = $this->soonest($db);
        if (null !== $next) {
            $this->occurrences->setDefault($db, $next->id);
        } elseif (null === $flagged) {
            $this->occurrences->ensureDefault($db); // nothing current, none flagged: the newest public event, if any
            $next = $this->occurrences->flaggedDefault($db);
        }
        if (null === $next) {
            return false; // a closed default stays on its thank-you page, or nothing is public
        }
        $this->audit->record($adminId, null === $adminId ? 'cli' : 'admin#'.$adminId, 'event.default_moved', 'event', (string) $next->id,
            ['from' => $flagged->id ?? $wasDefault, 'state' => $next->state], null === $adminId ? 'cli' : 'admin');

        return true;
    }
}
