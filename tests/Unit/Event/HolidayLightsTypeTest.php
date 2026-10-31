<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\OccurrenceSettings;
use App\Event\Type\HolidayLightsType;
use App\Shared\ValidationException;
use App\Twig\EventTypeExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/HolidayLightsTypeTest
 * @description The Christmas lights event type's contract: strict details (unknown keys refused, at least one
 *   display style, the FM station only from 87.5 to 108.0 MHz as one decimal and only with music, viewing mode),
 *   coercion and defaults, the strict public projection, statuses (off tonight is day-scoped), display rules equal
 *   to the shared fixture, filters, legend, stats, route profiles, its own event settings and whitelisted fragments.
 * @dependencies Event/Type/HolidayLightsType, Twig/EventTypeExtension
 */
#[CoversClass(HolidayLightsType::class)]
final class HolidayLightsTypeTest extends TestCase
{
    private static function normalize(array $in): array
    {
        return (new HolidayLightsType())->normalizeDetails($in, new OccurrenceSettings());
    }

    /** @return array<string,mixed> the field errors of an input the type refuses */
    private static function errors(array $in): array
    {
        try {
            self::normalize($in);
        } catch (ValidationException $e) {
            return $e->fields;
        }
        self::fail('expected ValidationException');
    }

    public function testDetailsAreCoercedAndFilledWithDefaultsInCanonicalOrder(): void
    {
        $d = self::normalize(['styles' => ['inflatables', 'classic', 'classic'], 'music_enabled' => 'yes', 'fm_frequency' => '88.7', 'treats' => 1, 'cocoa' => 'off', 'flashing_warning' => 'maybe']);
        self::assertSame(['styles' => ['classic', 'inflatables'], 'flashing_warning' => false, 'music_enabled' => true, 'fm_frequency' => '88.7',
            'viewing_mode' => 'drive_by_only', 'treats' => true, 'cocoa' => false], $d, 'canonical order, typed values, styles deduplicated in canonical order');
        self::assertSame('walk_up_welcome', self::normalize(['styles' => ['walkthrough'], 'viewing_mode' => 'walk_up_welcome'])['viewing_mode']);
        self::assertSame('drive_by_only', (new HolidayLightsType())->defaultDetails()['viewing_mode'], 'the cautious default: please do not approach');
    }

    public function testUnknownKeysAndStylesAreRefused(): void
    {
        self::assertSame(['details.glitter' => 'Unknown field.', 'details.photo_url' => 'Unknown field.'],
            self::errors(['styles' => ['classic'], 'glitter' => true, 'photo_url' => 'https://x.example/p.jpg']));
        self::assertSame(['styles'], array_keys(self::errors(['styles' => ['classic', 'lasers']])));
        self::assertSame(['styles'], array_keys(self::errors(['styles' => 'classic'])), 'a list, not a string');
        self::assertSame(['styles'], array_keys(self::errors(['styles' => ['a' => 'classic']])), 'a list, not an object');
        self::assertSame(['styles' => 'Choose at least one display style.'], self::errors(['styles' => []]));
        self::assertSame(['styles' => 'Choose at least one display style.'], self::errors(['treats' => true]), 'a new listing names its styles');
        self::assertSame(['viewing_mode' => 'Choose drive-by only or walk-up welcome.'], self::errors(['styles' => ['classic'], 'viewing_mode' => 'knock_please']));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function stations(): iterable
    {
        yield 'lowest' => ['87.5', '87.5'];
        yield 'highest' => ['108.0', '108.0'];
        yield 'three digits' => ['101.1', '101.1'];
        yield 'a number' => [88.7, '88.7'];
        yield 'leading zero' => ['088.7', '88.7'];
        yield 'below the band' => ['87.4', null];
        yield 'above the band' => ['108.1', null];
        yield 'AM' => ['1010', null];
        yield 'two decimals' => ['88.75', null];
        yield 'no decimal' => ['88', null];
        yield 'free text' => ['88.7 FM call me', null];
        yield 'a handle' => ['@lights', null];
        yield 'a list' => [['88.7'], null];
        yield 'infinite' => [INF, null];
    }

    #[DataProvider('stations')]
    public function testTheFmStationIsADecimalInsideTheBroadcastBand(mixed $input, ?string $expected): void
    {
        if (null === $expected) {
            self::assertSame(['fm_frequency' => 'Use an FM station like 88.7 (87.5 to 108.0).'], self::errors(['styles' => ['classic'], 'music_enabled' => true, 'fm_frequency' => $input]));

            return;
        }
        self::assertSame($expected, self::normalize(['styles' => ['classic'], 'music_enabled' => true, 'fm_frequency' => $input])['fm_frequency']);
    }

    public function testAStationNeedsMusicAndAnEmptyStationIsNone(): void
    {
        self::assertSame(['fm_frequency' => 'Turn on music to add its radio station, or leave the station empty.'], self::errors(['styles' => ['classic'], 'fm_frequency' => '88.7']));
        self::assertNull(self::normalize(['styles' => ['classic'], 'fm_frequency' => ''])['fm_frequency']);
        self::assertNull(self::normalize(['styles' => ['classic'], 'music_enabled' => true, 'fm_frequency' => null])['fm_frequency'], 'music without a station');
    }

    public function testThePublicProjectionIsStrictAndTyped(): void
    {
        $t = new HolidayLightsType();
        $public = $t->publicDetails(['styles' => ['lasers', 'animated', 5], 'music_enabled' => 0, 'fm_frequency' => '88.7', 'viewing_mode' => 'knock', 'owner_email' => 'x@example.test', 'treats' => 'yes']);
        self::assertSame(array_keys($t->defaultDetails()), array_keys($public), 'only the whitelisted keys');
        self::assertSame(['animated'], $public['styles']);
        self::assertNull($public['fm_frequency'], 'no station is shown without music');
        self::assertSame('drive_by_only', $public['viewing_mode'], 'an unknown stored value falls back to the cautious default');
        self::assertTrue($public['treats']);
        self::assertNull($t->publicDetails(['music_enabled' => true, 'fm_frequency' => '<b>88.7</b>'])['fm_frequency'], 'a damaged station is never shown');
        self::assertSame('101.1', $t->publicDetails(['music_enabled' => true, 'fm_frequency' => '101.1'])['fm_frequency']);
        self::assertSame([], $t->legacyFields($public), 'no legacy columns for this type');
    }

    public function testStatusesAndDisplayRulesMatchTheSharedFixture(): void
    {
        $t = new HolidayLightsType();
        self::assertSame(['active', 'off_tonight', 'finished'], array_keys($t->statuses()));
        self::assertSame(['off_tonight'], array_keys(array_filter($t->statuses(), static fn (array $s) => $s['day_scoped'])), 'only "off tonight" holds for one day');
        $fixture = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/schedule/display-status.json'), true, 32, \JSON_THROW_ON_ERROR);
        self::assertSame($fixture['rules']['holiday_lights'], $t->displayRules(), 'PHP and the browser apply the fixture\'s rules');
        foreach ($t->statuses() as $key => $s) {
            self::assertSame($s['day_scoped'], $t->displayRules()['statuses'][$key]['day_scoped']);
        }
    }

    public function testScheduleFiltersLegendStatsAndRoutes(): void
    {
        $t = new HolidayLightsType();
        self::assertSame(['nightly_range', 62, ['walking', 'driving']], [$t->scheduleKind(), $t->maxDays(), $t->routeProfiles()]);
        self::assertSame('Christmas Lights', $t->defaultName());
        self::assertSame(['driveBy', 'noFlashing', 'music', 'walkUp', 'openTonight'], array_column($t->filters(), 'key'));
        self::assertSame(['driveBy', 'noFlashing', 'music'], array_column(array_filter($t->filters(), static fn (array $f) => $f['quick']), 'key'), 'quick filters');
        self::assertSame('No flashing reported', $t->filters()[1]['label'], 'never "safe"');
        self::assertSame(['lights', 'music', 'inflatable', 'walkthrough'], array_column($t->legend(), 'cls'));
        self::assertSame([], $t->features());
        self::assertSame(['Holiday nights.', 'Bright lights.', '#38597F', '#CE9D3D'], [$t->defaultBranding()['headline'], $t->defaultBranding()['headline_accent'],
            $t->defaultBranding()['primary_color'], $t->defaultBranding()['accent_color']]);
        foreach ($t->copy() as $key => $text) {
            if (\is_string($text)) {
                self::assertDoesNotMatchRegularExpression('~\bsafe\b~i', $text, "copy.$key promises no safety");
            }
        }
        $homes = [['details' => ['music_enabled' => true, 'viewing_mode' => 'drive_by_only', 'flashing_warning' => false]],
            ['details' => ['music_enabled' => false, 'viewing_mode' => 'walk_up_welcome', 'flashing_warning' => true]]];
        self::assertSame(['music' => 1, 'drive_by' => 1, 'no_flashing' => 1], $t->stats($homes));
    }

    public function testItsOwnEventSettings(): void
    {
        $t = new HolidayLightsType();
        self::assertSame(['best_night', 'quiet_hours'], $t->settingKeys());
        self::assertSame(['2026-12-20', null], $t->normalizeSetting('best_night', '2026-12-20', '2026-12-01', '2027-01-03'));
        self::assertSame(['2027-01-01', null], $t->normalizeSetting('best_night', '2027-01-01', '2026-12-01', '2027-01-03'), 'across the year end');
        self::assertSame([null, null], $t->normalizeSetting('best_night', '', '2026-12-01', '2027-01-03'));
        self::assertNotNull($t->normalizeSetting('best_night', '2027-01-04', '2026-12-01', '2027-01-03')[1], 'outside the event');
        self::assertNotNull($t->normalizeSetting('best_night', '2026-02-30', '2026-12-01', '2027-01-03')[1], 'not a date');
        self::assertSame(['Quiet after 10 PM', null], $t->normalizeSetting('quiet_hours', "Quiet after\u{202E} 10 PM", '2026-12-01', '2027-01-03'));
        self::assertNotNull($t->normalizeSetting('quiet_hours', str_repeat('x', 121), '2026-12-01', '2027-01-03')[1]);
        self::assertSame(['best_night' => '2026-12-20', 'quiet_hours' => ''], $t->publicSettings(['best_night' => '2026-12-20', 'route_modes' => ['walking']]));
        self::assertSame(['best_night' => null, 'quiet_hours' => ''], $t->publicSettings([]));
    }

    public function testFragmentsAreWhitelistedAndExist(): void
    {
        $t = new HolidayLightsType();
        $root = \dirname(__DIR__, 3).'/templates/';
        foreach (array_keys($t->fragments()) as $name) {
            $path = EventTypeExtension::path($t, $name);
            self::assertMatchesRegularExpression('~^event/holiday_lights/_[a-z_]+\.html\.twig$~', $path);
            self::assertFileExists($root.$path);
        }
        $this->expectException(\InvalidArgumentException::class);
        EventTypeExtension::path($t, '../../base');
    }
}
