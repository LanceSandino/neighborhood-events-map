<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listing;

use App\Event\Type\HalloweenType;
use App\Listing\LegacyListingColumns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Listing/LegacyListingColumnsTest
 * @description The compatibility bridge (D-20261031-03): Halloween details map one to one onto the legacy columns,
 *   typed for DBAL, and details of any other shape are refused instead of being stored half-empty.
 * @dependencies Listing/LegacyListingColumns, Event/Type/HalloweenType
 */
#[CoversClass(LegacyListingColumns::class)]
final class LegacyListingColumnsTest extends TestCase
{
    public function testHalloweenDetailsBecomeTheLegacyColumns(): void
    {
        $details = array_replace((new HalloweenType())->defaultDetails(), ['candy' => 1, 'scare_level' => 'mild', 'stroller_friendly' => true]);
        $bindings = LegacyListingColumns::bindings($details);
        self::assertSame(LegacyListingColumns::COLUMNS, array_keys($bindings));
        self::assertTrue($bindings['candy']);
        self::assertSame('mild', $bindings['scare_level']);
        self::assertSame(implode(', ', LegacyListingColumns::COLUMNS), LegacyListingColumns::COLUMN_LIST);
        self::assertSame(implode(', ', array_map(static fn (string $c) => ':'.$c, LegacyListingColumns::COLUMNS)), LegacyListingColumns::PARAMETER_LIST);
        self::assertStringStartsWith('candy = EXCLUDED.candy, nonfood = EXCLUDED.nonfood', LegacyListingColumns::upsertAssignments());
        self::assertStringEndsWith('stroller_friendly = :stroller_friendly', LegacyListingColumns::updateAssignments());
        self::assertSame(array_fill_keys(HalloweenType::BOOLEANS, 'boolean'), LegacyListingColumns::bindingTypes());
        self::assertArrayNotHasKey('scare_level', LegacyListingColumns::bindingTypes());
    }

    public function testDetailsOfAnotherShapeAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('details without "candy" cannot be stored there');
        LegacyListingColumns::bindings(['display_styles' => ['classic'], 'music' => true]);
    }
}
