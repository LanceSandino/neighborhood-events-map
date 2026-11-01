<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\EventOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/EventStatesTest
 * @description What each event state allows (ADR 0011, D-20261101-01): who sees the event (visitors: upcoming, open,
 *   closed; residents in My house also a hidden one), whether it is the current event of its type (hidden, upcoming,
 *   open) and whether residents may list (a current event with dates). A hidden event is never public.
 * @dependencies Event/EventOccurrence
 */
final class EventStatesTest extends TestCase
{
    /** @return iterable<string, array{0:string, 1:bool, 2:bool, 3:bool}> state => public, residents see it, accepts listings */
    public static function states(): iterable
    {
        yield 'draft' => ['draft', false, false, false];
        yield 'hidden' => ['hidden', false, true, true];
        yield 'upcoming' => ['upcoming', true, true, true];
        yield 'open' => ['open', true, true, true];
        yield 'closed' => ['closed', true, true, false];
        yield 'archived' => ['archived', false, false, false];
    }

    #[DataProvider('states')]
    public function testWhoSeesAnEventAndWhetherItTakesListings(string $state, bool $public, bool $residents, bool $listings): void
    {
        $e = new EventOccurrence(1, 'halloween', 'Halloween', '2027-10-31', '2027-10-31', '17:00', '21:00', [], $state);
        self::assertSame([$public, $residents, $listings], [$e->isPublic(), $e->isForResidents(), $e->acceptsListings()]);
        self::assertSame(\in_array($state, ['hidden', 'upcoming', 'open'], true), \in_array($state, EventOccurrence::CURRENT_STATES, true), 'current: one per type');
    }

    public function testAHiddenEventWithoutDatesTakesNoListingsYet(): void
    {
        $e = new EventOccurrence(1, 'garage_sale', 'Sale', null, null, '08:00', '14:00', [], EventOccurrence::HIDDEN);
        self::assertSame([true, false, false], [$e->isHidden(), $e->isPublic(), $e->acceptsListings()]);
    }
}
