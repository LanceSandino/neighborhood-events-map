<?php

declare(strict_types=1);

namespace App\Tests\Unit\Address;

use App\Address\AddressNormalizer;
use App\Address\InvalidAddressException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


/**
 * Traceability: BLUEPRINT §3.1 (normalize house number, direction, suffix, whitespace).
 */
#[CoversClass(AddressNormalizer::class)]
final class AddressNormalizerTest extends TestCase
{
    /** @return iterable<array{0:string,1:string,2:string}> */
    public static function addresses(): iterable
    {
        yield ['123 Pumpkin Lane', '123 PUMPKIN LN', '123 Pumpkin Ln'];
        yield ['  123   pumpkin   ln. ', '123 PUMPKIN LN', '123 Pumpkin Ln'];
        yield ['45 North Main Street', '45 N MAIN ST', '45 N Main St'];
        yield ['45 N. Main St, Apt 4', '45 N MAIN ST UNIT 4', '45 N Main St Unit 4'];
        yield ['45 Main St #4B', '45 MAIN ST UNIT 4B', '45 Main St Unit 4B'];
        yield ['7a Willow Banks Street', '7A WILLOW BANKS ST', '7A Willow Banks St'];
        yield ['900 Lanternglow Place', '900 LANTERNGLOW PL', '900 Lanternglow Pl'];
        yield ['12 Parkway Drive South', '12 PARKWAY DR S', '12 Parkway Dr S'];
    }

    #[DataProvider('addresses')]
    public function testParseNormalizes(string $in, string $normalized, string $display): void
    {
        $p = (new AddressNormalizer())->parse($in);
        self::assertSame($normalized, $p['normalized']);
        self::assertSame($display, $p['display']);
    }

    public function testDifferentSpellingsCollideOnTheSameKey(): void
    {
        $n = new AddressNormalizer();
        self::assertSame($n->parse('123 Pumpkin Lane')['normalized'], $n->fromParts('123', 'pumpkin ln')['normalized']);
    }

    /** @return iterable<array{0:string}> */
    public static function invalid(): iterable
    {
        yield ['Pumpkin Lane'];
        yield ['1234567 Long Number St'];
        yield ['12'];
        yield ['<script>'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $in): void
    {
        $this->expectException(InvalidAddressException::class);
        (new AddressNormalizer())->parse($in);
    }

    public function testFromPartsValidatesUnit(): void
    {
        $this->expectException(InvalidAddressException::class);
        (new AddressNormalizer())->fromParts('12', 'Main St', 'TOO-LONG-UNIT');
    }
}
