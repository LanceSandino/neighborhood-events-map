<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\EventOccurrence;
use App\Event\OccurrenceInput;
use App\Event\OccurrenceSettings;
use App\Event\Schedule\ScheduleWindows;
use App\Event\Type\GarageSaleType;
use App\Shared\ValidationException;
use App\Twig\EventTypeExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/GarageSaleTypeTest
 * @description The garage sale event type's contract: strict details (unknown keys refused, at least one category
 *   from the fixed list and only those the sale allows, payment kinds cash/card/apps only, multi-family, early birds),
 *   the shared note refused when it carries contact details (phone, email, payment handle, web address), the strict
 *   public projection, statuses (paused and sold out are day-scoped) and display rules equal to the shared fixture,
 *   filters, legend, stats, route profiles, its event settings (1–3 sale days inside the event, allowed categories,
 *   the early-bird time) with the days a listing may choose, the schedule (days outside the sale refused, more than
 *   three refused, per-day hours with the end after the start, date boundaries) and whitelisted fragments.
 * @dependencies Event/Type/GarageSaleType, Event/OccurrenceInput, Event/Schedule/ScheduleWindows, Twig/EventTypeExtension
 */
#[CoversClass(GarageSaleType::class)]
final class GarageSaleTypeTest extends TestCase
{
    private const DAYS = ['2027-04-10', '2027-04-11'];

    private static function normalize(array $in, array $settings = []): array
    {
        return (new GarageSaleType())->normalizeDetails($in, new OccurrenceSettings([], $settings));
    }

    /** @return array<string,mixed> the field errors of an input the type refuses */
    private static function errors(array $in, array $settings = []): array
    {
        try {
            self::normalize($in, $settings);
        } catch (ValidationException $e) {
            return $e->fields;
        }
        self::fail('expected ValidationException');
    }

    private static function sale(array $settings = ['days' => self::DAYS], string $from = '2027-04-10', string $to = '2027-04-11'): EventOccurrence
    {
        return new EventOccurrence(9, 'garage_sale', 'Community Rummage Sale', $from, $to, '08:00', '14:00', $settings, 'active');
    }

    public function testDetailsAreCoercedAndFilledWithDefaultsInCanonicalOrder(): void
    {
        $d = self::normalize(['categories' => ['tools', 'kids_baby', 'tools'], 'multi_family' => 'yes', 'early_birds_allowed' => 0, 'payment_types' => ['apps', 'cash']]);
        self::assertSame(['categories' => ['kids_baby', 'tools'], 'multi_family' => true, 'early_birds_allowed' => false, 'payment_types' => ['cash', 'apps']], $d,
            'canonical order, typed values, lists deduplicated in canonical order');
        self::assertSame(['categories' => [], 'multi_family' => false, 'early_birds_allowed' => false, 'payment_types' => []], (new GarageSaleType())->defaultDetails());
        self::assertSame([], self::normalize(['categories' => ['free']])['payment_types'], 'payment kinds are optional');
        self::assertSame(['kids_baby', 'toys', 'furniture', 'clothing', 'tools', 'books_media', 'electronics', 'sports', 'household', 'free'], GarageSaleType::CATEGORIES);
    }

    public function testUnknownKeysCategoriesAndPaymentKindsAreRefused(): void
    {
        self::assertSame(['details.photo_url' => 'Unknown field.', 'details.price' => 'Unknown field.', 'details.note' => 'Unknown field.'],
            self::errors(['categories' => ['toys'], 'photo_url' => 'https://x.example/p.jpg', 'price' => 5, 'note' => 'second note']), 'the note is the shared listing note');
        self::assertSame(['categories'], array_keys(self::errors(['categories' => ['toys', 'weapons']])));
        self::assertSame(['categories'], array_keys(self::errors(['categories' => 'toys'])), 'a list, not a string');
        self::assertSame(['categories'], array_keys(self::errors(['categories' => ['a' => 'toys']])), 'a list, not an object');
        self::assertSame(['categories' => 'Choose at least one category.'], self::errors(['categories' => []]));
        self::assertSame(['categories' => 'Choose at least one category.'], self::errors(['multi_family' => true]), 'a new listing names its categories');
        $kinds = 'Choose payment kinds from cash, card and apps (the kind only, never a username or phone number).';
        self::assertSame(['payment_types' => $kinds], self::errors(['categories' => ['toys'], 'payment_types' => ['venmo']]));
        self::assertSame(['payment_types' => $kinds], self::errors(['categories' => ['toys'], 'payment_types' => ['cash', '@jane']]));
        self::assertSame(['payment_types' => $kinds], self::errors(['categories' => ['toys'], 'payment_types' => 'cash']));
    }

    public function testCategoriesStayInsideTheOnesTheSaleAllows(): void
    {
        $allowed = ['categories' => ['kids_baby', 'toys', 'free']];
        self::assertSame(['toys', 'free'], self::normalize(['categories' => ['free', 'toys']], $allowed)['categories']);
        self::assertSame(['categories' => 'This sale lists Kids & baby, Toys and Free items; choose from those.'], self::errors(['categories' => ['toys', 'tools']], $allowed));
        self::assertSame(['tools'], self::normalize(['categories' => ['tools']], ['categories' => 'damaged'])['categories'], 'a damaged setting allows the fixed list');
    }

    /** @return iterable<string, array{string, ?string}> note => the expected field error (null: published) */
    public static function notes(): iterable
    {
        $phone = 'Please remove the phone number: notes are public and never carry contact details.';
        $email = 'Please remove the email address: notes are public and never carry contact details.';
        $handle = 'Please remove the payment username or handle: choose the payment kinds instead (never a username or phone number).';
        $url = 'Please remove the web address: notes are public and never link to other sites.';
        yield 'phone' => ['Call 555-123-4567 for early access', $phone];
        yield 'dotted phone' => ['Text 555.123.4567', $phone];
        yield 'email' => ['Questions: jane@example.com', $email];
        yield 'spelled email' => ['jane at example dot com', $email];
        yield 'cashtag' => ['Pay $janedoe', $handle];
        yield 'venmo handle' => ['Venmo: jane-doe', $handle];
        yield 'web address' => ['More photos at example.com/sale', $url];
        foreach (['Sat 8-2', '$5 bags', '2-for-1', 'items 1-100', 'No early birds before 8:00', 'Venmo accepted', 'Baby gear in the garage, books on the porch.'] as $ok) {
            yield $ok => [$ok, null];
        }
    }

    #[DataProvider('notes')]
    public function testTheNoteNeverCarriesContactDetails(string $note, ?string $error): void
    {
        self::assertSame($error, (new GarageSaleType())->textProblem('note', $note));
    }

    public function testThePublicProjectionIsStrictAndTyped(): void
    {
        $t = new GarageSaleType();
        $public = $t->publicDetails(['categories' => ['weapons', 'toys', 5], 'multi_family' => 1, 'payment_types' => ['venmo', 'card'], 'owner_email' => 'x@example.test', 'note' => 'x']);
        self::assertSame(array_keys($t->defaultDetails()), array_keys($public), 'only the whitelisted keys');
        self::assertSame(['categories' => ['toys'], 'multi_family' => true, 'early_birds_allowed' => false, 'payment_types' => ['card']], $public);
        self::assertSame([], $t->legacyFields($public), 'no legacy columns for this type');
    }

    public function testStatusesAndDisplayRulesMatchTheSharedFixture(): void
    {
        $t = new GarageSaleType();
        self::assertSame(['active', 'paused', 'sold_out', 'closed'], array_keys($t->statuses()));
        self::assertSame(['paused', 'sold_out'], array_keys(array_filter($t->statuses(), static fn (array $s) => $s['day_scoped'])), 'paused and sold out hold for one day');
        self::assertSame(['Open', 'Paused today', 'Sold out today', 'Closed'], array_column($t->statuses(), 'label'));
        $fixture = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/schedule/display-status.json'), true, 32, \JSON_THROW_ON_ERROR);
        self::assertSame($fixture['rules']['garage_sale'], $t->displayRules(), 'PHP and the browser apply the fixture\'s rules');
    }

    public function testScheduleFiltersLegendStatsAndRoutes(): void
    {
        $t = new GarageSaleType();
        self::assertSame(['sale_days', 3, ['walking', 'driving']], [$t->scheduleKind(), $t->maxDays(), $t->routeProfiles()]);
        self::assertSame('Garage Sale', $t->defaultName());
        self::assertSame(['kidsToys', 'furniture', 'tools', 'household', 'electronics', 'free', 'openNow', 'multiFamily', 'earlyBirds'], array_column($t->filters(), 'key'));
        self::assertSame(['kidsToys', 'furniture', 'tools'], array_column(array_filter($t->filters(), static fn (array $f) => $f['quick']), 'key'), 'quick filters');
        self::assertSame(['sale', 'closed'], array_column($t->legend(), 'cls'));
        self::assertSame([], $t->features());
        $b = $t->defaultBranding();
        self::assertSame(['Good finds.', 'Close to home.', '#178D80', '#E77C5D'], [$b['headline'], $b['headline_accent'], $b['primary_color'], $b['accent_color']]);
        self::assertSame(['Having a sale?', '🏷️ Your sale stops'], [$t->copy()['cta_title'], $t->copy()['route_title']]);
        $homes = [['details' => ['categories' => ['free', 'toys'], 'multi_family' => true, 'early_birds_allowed' => false]],
            ['details' => ['categories' => ['tools'], 'multi_family' => false, 'early_birds_allowed' => true]]];
        self::assertSame(['multi_family' => 1, 'early_birds' => 1, 'free' => 1], $t->stats($homes));
    }

    public function testItsOwnEventSettings(): void
    {
        $t = new GarageSaleType();
        self::assertSame(['days', 'categories', 'early_bird_time'], $t->settingKeys());
        self::assertSame([self::DAYS, null], $t->normalizeSetting('days', ['2027-04-11', '2027-04-10', '2027-04-10'], '2027-04-10', '2027-04-11'), 'sorted, once each');
        self::assertSame('Choose 1 to 3 sale days from 2027-04-09 to 2027-04-18.', $t->normalizeSetting('days', ['2027-04-10', '2027-04-11', '2027-04-17', '2027-04-18'], '2027-04-09', '2027-04-18')[1], 'at most three');
        self::assertNotNull($t->normalizeSetting('days', ['2027-04-12'], '2027-04-10', '2027-04-11')[1], 'outside the event');
        self::assertNotNull($t->normalizeSetting('days', [], '2027-04-10', '2027-04-11')[1], 'at least one');
        self::assertNotNull($t->normalizeSetting('days', '2027-04-10', '2027-04-10', '2027-04-11')[1], 'a list');
        self::assertNotNull($t->normalizeSetting('days', ['2027-02-30'], '2027-02-01', '2027-03-01')[1], 'a real date');
        self::assertSame([['kids_baby', 'free'], null], $t->normalizeSetting('categories', ['free', 'kids_baby'], '2027-04-10', '2027-04-11'));
        self::assertNotNull($t->normalizeSetting('categories', [], '2027-04-10', '2027-04-11')[1]);
        self::assertNotNull($t->normalizeSetting('categories', ['weapons'], '2027-04-10', '2027-04-11')[1]);
        self::assertSame(['07:30', null], $t->normalizeSetting('early_bird_time', '07:30', '2027-04-10', '2027-04-11'));
        self::assertSame([null, null], $t->normalizeSetting('early_bird_time', '', '2027-04-10', '2027-04-11'));
        self::assertNotNull($t->normalizeSetting('early_bird_time', '7am', '2027-04-10', '2027-04-11')[1]);
        self::assertSame([null, 'Unknown field.'], $t->normalizeSetting('best_night', '2027-04-10', '2027-04-10', '2027-04-11'));
        self::assertSame(GarageSaleType::CATEGORIES, array_keys($t->settingOptions()['categories']));
        self::assertSame(['days' => self::DAYS, 'categories' => GarageSaleType::CATEGORIES, 'early_bird_time' => null], $t->publicSettings(['days' => self::DAYS, 'route_modes' => ['walking']]));
        self::assertSame(['days' => [], 'categories' => ['toys'], 'early_bird_time' => '07:00'], $t->publicSettings(['categories' => ['toys'], 'early_bird_time' => '07:00']));
    }

    public function testTheSaleDaysAreCompletedAndCheckedAsAWhole(): void
    {
        $t = new GarageSaleType();
        self::assertSame([['days' => self::DAYS], []], $t->finishSettings([], '2027-04-10', '2027-04-11'), 'a weekend sale takes its days');
        self::assertSame([['days' => ['2027-04-10']], []], $t->finishSettings([], '2027-04-10', '2027-04-10'));
        self::assertSame(['settings.days' => 'Choose the sale days (1 to 3) inside the event.'], $t->finishSettings([], '2027-04-09', '2027-04-18')[1], 'a longer span names its days');
        self::assertSame(['settings.days' => 'Choose the sale days (1 to 3) inside the event.'], $t->finishSettings(['days' => ['2027-04-17']], '2027-04-09', '2027-04-12')[1], 'days left outside new dates');
        $fields = OccurrenceInput::normalize(['type' => 'garage_sale', 'name' => 'Community Rummage Sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-17',
            'default_start' => '08:00', 'default_end' => '14:00', 'settings' => ['days' => ['2027-04-10', '2027-04-17'], 'categories' => ['toys', 'free'], 'early_bird_time' => '07:00']], $t);
        self::assertSame(['days' => ['2027-04-10', '2027-04-17'], 'categories' => ['toys', 'free'], 'early_bird_time' => '07:00'], $fields['settings']);
        try {
            OccurrenceInput::normalize(['starts_on' => '2027-04-12'], $t, self::sale(['days' => self::DAYS], '2027-04-10', '2027-04-11'));
            self::fail('moving the event away from its sale days is refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('settings.days', $e->fields);
        }
    }

    public function testAListingChoosesOnlySaleDaysWithItsOwnHoursEachDay(): void
    {
        $t = new GarageSaleType();
        $two = self::sale(['days' => ['2027-04-10', '2027-04-17']], '2027-04-10', '2027-04-17');
        self::assertSame(['2027-04-10', '2027-04-17'], $t->listingDates($two));
        self::assertSame(self::DAYS, $t->listingDates(self::sale([])), 'without days: the event\'s days (at most three)');
        $w = static fn (string $d, string $a, string $b) => ['date' => $d, 'start' => $a, 'end' => $b];
        $ok = ScheduleWindows::normalize('sale_days', $two, [$w('2027-04-17', '09:00', '12:00'), $w('2027-04-10', '07:30', '14:00')], ScheduleWindows::DAY, null, $t->listingDates($two));
        self::assertSame([$w('2027-04-10', '07:30', '14:00'), $w('2027-04-17', '09:00', '12:00')], $ok, 'different hours each day, sorted');
        $refused = static function (array $windows, EventOccurrence $e) use ($t): array {
            try {
                ScheduleWindows::normalize('sale_days', $e, $windows, ScheduleWindows::DAY, null, $t->listingDates($e));
            } catch (ValidationException $x) {
                return $x->fields;
            }
            self::fail('expected a refusal');
        };
        self::assertSame(['windows.0' => 'Choose one of the event\'s days: 2027-04-10, 2027-04-17.'], $refused([$w('2027-04-12', '08:00', '12:00')], $two), 'a day of the span that is not a sale day');
        self::assertSame(['windows.0' => 'Choose a day from 2027-04-10 to 2027-04-11.'], $refused([$w('2027-04-09', '08:00', '12:00')], self::sale()), 'the day before');
        self::assertSame(['windows.1' => 'Choose a day from 2027-04-10 to 2027-04-11.'], $refused([$w('2027-04-11', '08:00', '12:00'), $w('2027-04-12', '08:00', '12:00')], self::sale()), 'the day after');
        self::assertSame(['windows' => 'Choose at most 3 days.'], $refused([$w('2027-04-09', '08:00', '12:00'), $w('2027-04-10', '08:00', '12:00'), $w('2027-04-11', '08:00', '12:00'), $w('2027-04-12', '08:00', '12:00')],
            self::sale(['days' => ['2027-04-09', '2027-04-10', '2027-04-11']], '2027-04-09', '2027-04-12')));
        self::assertSame(['windows.1' => 'End time must be later than the start time.'], $refused([$w('2027-04-10', '08:00', '12:00'), $w('2027-04-11', '13:00', '13:00')], self::sale()));
        self::assertSame(['windows.0' => 'End time must be later than the start time.'], $refused([$w('2027-04-10', '14:00', '08:00')], self::sale()));
        $monthEnd = self::sale(['days' => ['2027-04-30', '2027-05-01']], '2027-04-30', '2027-05-01');
        self::assertCount(2, ScheduleWindows::normalize('sale_days', $monthEnd, [$w('2027-04-30', '08:00', '13:00'), $w('2027-05-01', '08:00', '13:00')], ScheduleWindows::DAY, null, $t->listingDates($monthEnd)), 'across a month end');
    }

    public function testFragmentsAreWhitelistedAndExist(): void
    {
        $t = new GarageSaleType();
        $root = \dirname(__DIR__, 3).'/templates/';
        foreach (array_keys($t->fragments()) as $name) {
            $path = EventTypeExtension::path($t, $name);
            self::assertMatchesRegularExpression('~^event/garage_sale/_[a-z_]+\.html\.twig$~', $path);
            self::assertFileExists($root.$path);
        }
        $this->expectException(\InvalidArgumentException::class);
        EventTypeExtension::path($t, '../../base');
    }
}
