<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\OccurrenceSettings;
use App\Event\Type\HalloweenType;
use App\Listing\LegacyListingColumns;
use App\Shared\ValidationException;
use App\Twig\EventTypeExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/HalloweenTypeTest
 * @description The Halloween event type's contract: strict details (unknown keys refused with field errors),
 *   the 0.9 coercion rules, switched-off offerings forced off, the strict public projection, statuses,
 *   filters, legend, stats and its whitelisted Twig fragments (which exist).
 * @dependencies Event/Type/HalloweenType, Listing/LegacyListingColumns, Twig/EventTypeExtension
 */
#[CoversClass(HalloweenType::class)]
final class HalloweenTypeTest extends TestCase
{
    private const ALL_ON = ['candy' => true, 'nonfood_treats' => true, 'adult_refreshments' => true, 'sensory_filters' => true];

    public function testUnknownDetailKeysAreRefusedWithFieldErrors(): void
    {
        try {
            (new HalloweenType())->normalizeDetails(['candy' => true, 'glitter' => true, 'photo_url' => 'https://x.example/p.jpg'], new OccurrenceSettings(self::ALL_ON));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['details.glitter' => 'Unknown field.', 'details.photo_url' => 'Unknown field.'], $e->fields);
        }
    }

    public function testDetailsAreCoercedLikeVersion09AndFilledWithDefaults(): void
    {
        $t = new HalloweenType();
        $d = $t->normalizeDetails(['candy' => 'yes', 'low_noise' => 'true', 'jump_scares' => 'maybe', 'loud_audio' => 1, 'stroller_friendly' => 'off', 'scare_level' => 'mild'], new OccurrenceSettings(self::ALL_ON));
        self::assertSame(['candy' => true, 'nonfood' => false, 'adult_drinks' => false, 'low_noise' => true, 'jump_scares' => false,
            'flashing_lights' => false, 'loud_audio' => true, 'scare_level' => 'mild', 'stroller_friendly' => false], $d, 'canonical order, typed values');
        self::assertSame(array_replace($t->defaultDetails(), ['nonfood' => true]), $t->normalizeDetails(['nonfood' => true], new OccurrenceSettings(self::ALL_ON)), 'missing keys take their defaults');
        self::assertSame(LegacyListingColumns::COLUMNS, array_keys($t->defaultDetails()), 'details keys are the legacy columns, in table order');
    }

    public function testSwitchedOffOfferingsAreForcedOffAndOneOfferingIsRequired(): void
    {
        $t = new HalloweenType();
        $d = $t->normalizeDetails(['candy' => true, 'adult_drinks' => true], new OccurrenceSettings(['adult_refreshments' => false] + self::ALL_ON));
        self::assertTrue($d['candy']);
        self::assertFalse($d['adult_drinks'], 'the organizer turned 21+ refreshments off');
        try {
            $t->normalizeDetails(['candy' => true, 'scare_level' => 'deadly'], new OccurrenceSettings(['candy' => false] + self::ALL_ON));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['offerings', 'scare_level'], array_keys($e->fields), 'the 0.9 messages, in the 0.9 order');
            self::assertSame('Choose at least one: candy, non-food treats or adult refreshments.', $e->fields['offerings']);
            self::assertSame('Choose gentle, mild or intense.', $e->fields['scare_level']);
        }
    }

    public function testThePublicProjectionIsStrictAndTyped(): void
    {
        $t = new HalloweenType();
        $public = $t->publicDetails(['candy' => 1, 'scare_level' => 'nightmare', 'owner_email' => 'x@example.test', 'low_noise' => true]);
        self::assertSame(array_keys($t->defaultDetails()), array_keys($public), 'only the whitelisted keys');
        self::assertTrue($public['candy']);
        self::assertTrue($public['low_noise']);
        self::assertSame('gentle', $public['scare_level'], 'an unknown stored value falls back to the default');
        self::assertArrayNotHasKey('owner_email', $public);
        self::assertSame($public, $t->legacyFields($public), 'Halloween keeps its flat public fields during the compatibility window');
    }

    public function testStatusesFiltersLegendAndStats(): void
    {
        $t = new HalloweenType();
        self::assertSame(['active', 'paused', 'out_of_candy', 'done'], array_keys($t->statuses()), 'the 0.9 statuses');
        self::assertSame([], array_keys(array_filter($t->statuses(), static fn (array $s) => $s['day_scoped'])), 'no Halloween status is day-scoped');
        self::assertSame(['active' => 'Handing out', 'paused' => 'Paused for now'], array_filter(array_map(static fn (array $s) => $s['register_label'] ?? null, $t->statuses())));
        self::assertSame(['candy', 'lowSensory', 'adult', 'noScares', 'noFlashing', 'noLoud', 'nonfood', 'stroller'], array_column($t->filters(), 'key'));
        self::assertSame(['candy', 'lowSensory', 'adult'], array_column(array_filter($t->filters(), static fn (array $f) => $f['quick']), 'key'), 'quick filters');
        self::assertSame(['candy', 'gentle', 'spooky', 'adult'], array_column($t->legend(), 'cls'));
        self::assertSame(['candy', 'nonfood_treats', 'adult_refreshments', 'sensory_filters'], array_keys($t->features()));
        self::assertSame(['single_day', 1, ['walking']], [$t->scheduleKind(), $t->maxDays(), $t->routeProfiles()]);
        self::assertSame('Halloween', $t->defaultName());
        self::assertCount(10, $t->copy()['reminders']);

        $home = static fn (array $d) => ['details' => $d + $t->defaultDetails()];
        $homes = [$home(['candy' => true, 'low_noise' => true]), $home(['candy' => true, 'low_noise' => true, 'scare_level' => 'intense']), $home(['adult_drinks' => true])];
        self::assertSame(['candy' => 2, 'low_sensory' => 1, 'adult' => 1], $t->stats($homes), 'sensory-friendly: quiet, no flashing, loud audio or big scares');
    }

    public function testFragmentsAreWhitelistedAndExist(): void
    {
        $t = new HalloweenType();
        $root = \dirname(__DIR__, 3).'/templates/';
        foreach (array_keys($t->fragments()) as $name) {
            $path = EventTypeExtension::path($t, $name);
            self::assertMatchesRegularExpression('~^event/halloween/_[a-z_]+\.html\.twig$~', $path);
            self::assertFileExists($root.$path);
        }
        $this->expectException(\InvalidArgumentException::class);
        EventTypeExtension::path($t, '../../base');
    }
}
