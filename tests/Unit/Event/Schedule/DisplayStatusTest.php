<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Schedule;

use App\Event\Schedule\DisplayStatus;
use App\Event\Type\HalloweenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/Schedule/DisplayStatusTest
 * @description The display status (D-20261031-08) on the shared fixtures that tests/js/schedule.test.mjs reads too:
 *   DST change days in New York and Chicago, New Year's Eve, month ends, day-scoped statuses, before, inside and after
 *   a window, other days. Halloween's rules in code are the fixture's.
 * @dependencies Event/Schedule/DisplayStatus, Event/Type/HalloweenType, tests/fixtures/schedule/display-status.json
 */
final class DisplayStatusTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/schedule/display-status.json'), true, 32, \JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0:array<string,mixed>, 1:array<string,mixed>}> */
    public static function cases(): iterable
    {
        $f = self::fixture();
        foreach ($f['cases'] as $c) {
            yield $c['name'] => [$f['rules'][$c['type']], $c];
        }
    }

    /**
     * @param array<string,mixed> $rules
     * @param array<string,mixed> $c
     */
    #[DataProvider('cases')]
    public function testTheSharedCase(array $rules, array $c): void
    {
        self::assertSame($c['expected'], DisplayStatus::derive($rules, $c['status'], $c['status_date'], $c['windows'], new \DateTimeImmutable($c['now']), new \DateTimeZone($c['timezone'])));
    }

    public function testThereAreEnoughCasesAndHalloweensRulesAreTheFixtures(): void
    {
        $f = self::fixture();
        self::assertGreaterThanOrEqual(40, \count($f['cases']));
        self::assertSame($f['rules']['halloween'], (new HalloweenType())->displayRules());
        foreach ($f['rules'] as $key => $rules) {
            self::assertSame(DisplayStatus::PHASES, array_keys($rules['phases']), "$key names every phase");
            self::assertArrayHasKey('active', $rules['statuses'], "$key has an active status");
            self::assertNull($rules['statuses']['active']['display'], "$key: active shows the window phase");
        }
        self::assertSame(array_keys((new HalloweenType())->statuses()), array_keys($f['rules']['halloween']['statuses']), 'one rule per Halloween status');
    }

    public function testTheWindowPhaseIsStartInclusiveAndEndExclusive(): void
    {
        $w = [['date' => '2026-10-31', 'start' => '17:30', 'end' => '20:30']];
        self::assertSame('later_today', DisplayStatus::phase($w, '2026-10-31', '17:29'));
        self::assertSame('inside', DisplayStatus::phase($w, '2026-10-31', '17:30'));
        self::assertSame('inside', DisplayStatus::phase($w, '2026-10-31', '20:29'));
        self::assertSame('ended_today', DisplayStatus::phase($w, '2026-10-31', '20:30'));
        self::assertSame('not_today', DisplayStatus::phase($w, '2026-11-01', '18:00'));
        self::assertSame('not_today', DisplayStatus::phase([], '2026-11-01', '18:00'));
    }
}
