<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listing;

use App\Community\CommunityConfig;
use App\Community\CommunitySchema;
use App\Event\EventTypeRegistry;
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

    public function testDetailsMayComeAsAnObjectThatWinsOverFlatFields(): void
    {
        $d = ListingDraft::fromInput(['start' => '18:00', 'end' => '20:00', 'details' => ['nonfood' => true, 'scare_level' => 'intense'], 'candy' => true, 'scare_level' => 'mild'], self::community());
        self::assertSame(['candy' => true, 'nonfood' => true, 'adult_drinks' => false, 'low_noise' => false, 'jump_scares' => false,
            'flashing_lights' => false, 'loud_audio' => false, 'scare_level' => 'intense', 'stroller_friendly' => false], $d['details']);
        foreach ($d['details'] as $k => $v) {
            self::assertSame($v, $d[$k], "the flat $k (compatibility window) equals details.$k");
        }
        self::assertSame($d, ListingDraft::fromInput($d, self::community()), 'a draft is valid input and normalizes to itself');
    }

    public function testUnknownFieldsAreRefusedAtEitherLevel(): void
    {
        try {
            ListingDraft::fromInput(['candy' => true, 'phone' => '555-0100', 'details' => ['glitter' => true]], self::community());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['details.glitter' => 'Unknown field.', 'phone' => 'Unknown field.'], $e->fields);
        }
        try {
            ListingDraft::fromInput(['candy' => true, 'details' => ['candy', 'nonfood']], self::community());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['details' => 'Send the listing details as an object.'], $e->fields);
        }
    }

    public function testADraftSavedBy09GetsItsDetailsFromTheFlatFields(): void
    {
        $old = ['start' => '17:30', 'end' => '20:30', 'candy' => true, 'nonfood' => false, 'adult_drinks' => false, 'low_noise' => true, 'jump_scares' => false,
            'flashing_lights' => false, 'loud_audio' => false, 'stroller_friendly' => true, 'scare_level' => 'mild', 'note' => 'Hi', 'status' => 'active', 'published' => true];
        $type = EventTypeRegistry::builtIn()->get('halloween');
        $with = ListingDraft::withDetails($old, $type);
        self::assertSame(['candy' => true, 'nonfood' => false, 'adult_drinks' => false, 'low_noise' => true, 'jump_scares' => false,
            'flashing_lights' => false, 'loud_audio' => false, 'scare_level' => 'mild', 'stroller_friendly' => true], $with['details']);
        self::assertSame($with, ListingDraft::withDetails($with, $type), 'a draft with details stays as it is');
    }
}
