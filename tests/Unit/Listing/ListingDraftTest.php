<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listing;

use App\Community\CommunityConfig;
use App\Community\CommunitySchema;
use App\Listing\ListingDraft;
use App\Shared\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


/**
 * Traceability: BLUEPRINT §3.3 listing fields; §7 listings constraints.
 */
#[CoversClass(ListingDraft::class)]
final class ListingDraftTest extends TestCase
{
    private static function community(array $features = []): CommunityConfig
    {
        $s = (new CommunitySchema())->normalize(['community' => ['id' => 'x1', 'name' => 'X'], 'event' => ['date' => '2026-10-31'], 'features' => $features]);

        return new CommunityConfig($s, null, false, null, []);
    }

    public function testNormalizesValidDraft(): void
    {
        $d = ListingDraft::fromInput(['start' => '18:00', 'end' => '21:30', 'candy' => true, 'low_noise' => 'true', 'scare_level' => 'mild',
            'note' => "  Allergy-friendly bin.\u{0007}  ", 'status' => 'out_of_candy'], self::community());
        self::assertSame('18:00', $d['start']);
        self::assertSame('21:30', $d['end']);
        self::assertTrue($d['candy']);
        self::assertTrue($d['low_noise']);
        self::assertSame('Allergy-friendly bin.', $d['note']);
        self::assertSame('out_of_candy', $d['status']);
        self::assertTrue($d['published']);
    }

    public function testZeroCandyAllowedWithNonfood(): void
    {
        $d = ListingDraft::fromInput(['nonfood' => true], self::community());
        self::assertFalse($d['candy']);
        self::assertTrue($d['nonfood']);
    }

    public function testRejectsInvalidCombination(): void
    {
        try {
            ListingDraft::fromInput(['start' => '21:00', 'end' => '18:00', 'scare_level' => 'deadly', 'status' => 'gone', 'note' => str_repeat('x', 401)], self::community());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['end', 'offerings', 'scare_level', 'status', 'note'], array_keys($e->fields));
        }
    }

    public function testDisabledFeatureIsForcedOff(): void
    {
        $d = ListingDraft::fromInput(['candy' => true, 'adult_drinks' => true], self::community(['adult_refreshments' => false]));
        self::assertFalse($d['adult_drinks']);
    }

    public function testPartialUpdateKeepsCurrentValues(): void
    {
        $current = ListingDraft::fromInput(['candy' => true, 'start' => '17:00', 'end' => '20:00', 'note' => 'hi'], self::community());
        $d = ListingDraft::fromInput(['status' => 'done'], self::community(), true, $current);
        self::assertSame('done', $d['status']);
        self::assertSame('hi', $d['note']);
        self::assertSame('20:00', $d['end']);
    }
}
