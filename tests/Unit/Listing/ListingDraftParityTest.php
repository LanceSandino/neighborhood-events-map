<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listing;

use App\Community\CommunityConfig;
use App\Community\CommunitySchema;
use App\Event\EventTypeRegistry;
use App\Listing\ListingDraft;
use App\Shared\ValidationException;
use App\Tests\Support\Legacy\ListingDraft090;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Listing/ListingDraftParityTest
 * @description Normalization parity with 0.9 (ROADMAP Stage 1): for 600 seeded random Halloween inputs — every
 *   boolean spelling, valid and invalid scare levels, statuses, times and notes, partial updates of a current
 *   listing, each community feature switched on or off — the event-type ListingDraft accepts exactly what the
 *   frozen 0.9 ListingDraft accepted, with the same values, and refuses exactly what it refused, with the same
 *   field errors in the same order. The new draft only adds `details`, which equals its flat fields.
 * @dependencies Listing/ListingDraft, tests/Support/Legacy/ListingDraft090
 */
#[CoversClass(ListingDraft::class)]
final class ListingDraftParityTest extends TestCase
{
    private const FLAGS = ['candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares', 'flashing_lights', 'loud_audio', 'stroller_friendly'];
    private const BOOLS = [true, false, 'true', 'false', 'yes', 'no', 'on', 'off', '1', '0', 1, 0, 'maybe', '', null];
    private const SCARES = ['gentle', 'mild', 'intense', 'deadly', 'MILD', '', 3, null];
    private const STATUSES = ['active', 'paused', 'out_of_candy', 'done', 'gone', 'Active', 7, null];
    private const TIMES = ['17:00', '18:30', '21:00', '20:59:59', ' 19:15 ', '9:00', '24:00', '', 1800, null];
    private const NOTES = ['', 'Porch light on.', "  Allergy-friendly bin.\u{0007}  ", "Line one\r\n\r\n\r\nLine two", 7, null];

    public function testSixHundredSeededInputsNormalizeExactlyAsIn09(): void
    {
        mt_srand(20261031);
        $compared = ['accepted' => 0, 'refused' => 0];
        for ($i = 0; $i < 600; ++$i) {
            $community = self::community([
                'candy' => 0 !== mt_rand(0, 3), 'nonfood_treats' => 0 !== mt_rand(0, 3),
                'adult_refreshments' => 0 !== mt_rand(0, 3), 'sensory_filters' => 0 !== mt_rand(0, 1),
            ]);
            $partial = 0 === mt_rand(0, 2);
            $current = $partial ? ListingDraft090::fromInput(['candy' => true, 'note' => 'Earlier note', 'start' => '18:00', 'end' => '20:00', 'scare_level' => 'mild'], self::community([])) : null;
            $input = self::randomInput();

            [$old, $oldError] = self::outcome(static fn () => ListingDraft090::fromInput($input, $community, $partial, $current));
            [$new, $newError] = self::outcome(static fn () => ListingDraft::fromInput($input, $community, $partial, null === $current ? null : ListingDraft::withDetails($current, EventTypeRegistry::builtIn()->get('halloween'))));

            $case = json_encode(['input' => $input, 'partial' => $partial, 'features' => $community->features()], \JSON_PARTIAL_OUTPUT_ON_ERROR);
            self::assertSame($oldError, $newError, "same refusal, same fields in the same order: $case");
            if (null !== $old) {
                self::assertIsArray($new);
                foreach ($old as $k => $v) {
                    self::assertSame($v, $new[$k], "$k: $case");
                }
                self::assertSame([], array_diff(array_keys($new), [...array_keys($old), 'details']), 'only details is new');
                foreach ($new['details'] as $k => $v) {
                    self::assertSame($old[$k], $v, "details.$k equals the 0.9 field: $case");
                }
                ++$compared['accepted'];
            } else {
                ++$compared['refused'];
            }
        }
        self::assertGreaterThan(100, $compared['accepted'], 'the matrix exercises accepted listings');
        self::assertGreaterThan(100, $compared['refused'], 'and refused ones');
    }

    /** @return array<string,mixed> */
    private static function randomInput(): array
    {
        $in = [];
        foreach (self::FLAGS as $f) {
            if (0 !== mt_rand(0, 2)) {
                $in[$f] = self::pick(self::BOOLS);
            }
        }
        foreach (['scare_level' => self::SCARES, 'status' => self::STATUSES, 'start' => self::TIMES, 'end' => self::TIMES, 'note' => self::NOTES, 'published' => self::BOOLS] as $f => $values) {
            if (0 === mt_rand(0, 2)) {
                $in[$f] = self::pick($values);
            }
        }
        if (0 === mt_rand(0, 4)) {
            $in['note'] = str_repeat('x', mt_rand(395, 405));
        }

        return $in;
    }

    /**
     * @param list<mixed> $values
     */
    private static function pick(array $values): mixed
    {
        return $values[mt_rand(0, \count($values) - 1)];
    }

    /** @return array{0: ?array<string,mixed>, 1: ?array<string,string>} the draft, or the refused fields */
    private static function outcome(callable $normalize): array
    {
        try {
            return [$normalize(), null];
        } catch (ValidationException $e) {
            return [null, $e->fields];
        }
    }

    /** @param array<string,bool> $features */
    private static function community(array $features): CommunityConfig
    {
        $s = (new CommunitySchema())->normalize(['community' => ['id' => 'x1', 'name' => 'X'], 'event' => ['date' => '2026-10-31'], 'features' => $features]);

        return new CommunityConfig($s, null, false, null, []);
    }
}
