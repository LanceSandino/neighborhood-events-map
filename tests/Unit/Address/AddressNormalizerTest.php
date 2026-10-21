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

    /**
     * Addresses never need special characters: anything outside letters, digits, spaces and . , ' - # is
     * refused instead of being silently stripped (which turned "1<2" into 12 and "Peña" into "PEA"), and
     * look-alike letters from other scripts cannot create a second "same-looking" address.
     *
     * @return iterable<string, array{0:string,1:string,2:?string}>
     */
    public static function specialCharacters(): iterable
    {
        yield 'markup in the street' => ['12', 'Pumpkin<script> Ln', null];
        yield 'quote' => ['12', 'Pump"kin Ln', null];
        yield 'semicolon' => ['12', 'Pumpkin Ln; DROP', null];
        yield 'ampersand' => ['12', 'Pumpkin & Ln', null];
        yield 'percent' => ['12', 'Pumpkin%20Ln', null];
        yield 'non-ASCII letter' => ['12', 'Peña Ln', null];
        yield 'Cyrillic look-alike' => ['12', "\u{0420}umpkin Ln", null];
        yield 'full-width digits' => ["\u{FF11}\u{FF12}", 'Pumpkin Ln', null];
        yield 'markup in the house number' => ['1<2', 'Pumpkin Ln', null];
        yield 'markup in the unit' => ['12', 'Pumpkin Ln', '<b>4'];
        yield 'emoji' => ['12', "Pumpkin \u{1F383} Ln", null];
        yield 'right-to-left override' => ['12', "Pumpkin\u{202E} Ln", null];
        yield 'tab' => ['12', "Pumpkin\tLn", null];
    }

    #[DataProvider('specialCharacters')]
    public function testSpecialCharactersAreRefused(string $house, string $street, ?string $unit): void
    {
        $this->expectException(InvalidAddressException::class);
        $this->expectExceptionMessage('letters, numbers');
        (new AddressNormalizer())->fromParts($house, $street, $unit);
    }

    public function testParseRefusesSpecialCharactersAndKeepsOrdinaryPunctuation(): void
    {
        $n = new AddressNormalizer();
        self::assertSame("12 ST JOHNS RD", $n->parse("12 St. John's Road")['normalized']);
        self::assertSame('45 N MAIN ST UNIT 4-B', $n->parse('45 N. Main St, Apt #4-B')['normalized']);
        self::assertTrue(AddressNormalizer::isPlain("12 St. John's Rd, #4"));
        self::assertFalse(AddressNormalizer::isPlain('12 Pumpkin <Ln>'));
        $this->expectException(InvalidAddressException::class);
        $n->parse('12 Pumpkin Ln<img src=x>');
    }

    public function testFromPartsValidatesUnit(): void
    {
        $this->expectException(InvalidAddressException::class);
        (new AddressNormalizer())->fromParts('12', 'Main St', 'TOO-LONG-UNIT');
    }
}
