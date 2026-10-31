<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\ContactDetails;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Shared/ContactDetailsTest
 * @description The contact-detail check on public free text (garage-sale notes, D-20261021-23): phone numbers in
 *   their usual shapes (spaced, dotted, dashed, parenthesized, with a country code), email addresses (also written
 *   "name at domain dot com"), payment handles ($cashtags, @handles, a payment app followed by a username) and web
 *   addresses are found; ordinary sale notes with times, prices, sizes, ranges and dates are not. Number words
 *   ("five five five") are out of scope.
 * @dependencies Shared/ContactDetails
 */
#[CoversClass(ContactDetails::class)]
final class ContactDetailsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> text => the kind found */
    public static function found(): iterable
    {
        foreach ([
            'Call 555-123-4567', 'call (555) 123-4567', '(555)123-4567', '555.123.4567', '555 123 4567', 'text 5551234567', '+1 555 123 4567',
            '+1-555-123-4567', '+15551234567', '1-800-555-0199', '555 - 123 - 4567', 'text me at 555-1234', 'or 555.1234', '+44 20 7946 0958',
            'Questions? ５５５-１２３-４５６７', 'Phone: 555 123 4567 after 8',
        ] as $text) {
            yield 'phone: '.$text => [$text, 'phone'];
        }
        foreach ([
            'jane@example.com', 'Email JANE.DOE+sale@Mail.Example.org', 'jane @ example . com', 'jane at example dot com', 'jane (at) example (dot) com',
            'jane [at] example [dot] com', 'jane AT example DOT com', 'jane(at)example(dot)com', 'jane(at)example.com', 'jane＠example.com',
        ] as $text) {
            yield 'email: '.$text => [$text, 'email'];
        }
        foreach ([
            '$janedoe', 'Cash App $JaneD', '@jane_doe', 'venmo @jane', 'Venmo: jane-doe', 'venmo jane-doe-12', 'Venmo JaneDoe', 'Zelle me at jane',
            'PayPal: JaneDoe', 'cashapp janed', 'Cash app - janed', 'Venmo is jane_doe', 'pay pal: thrifty_jane', 'Find us @thrifty.jane',
        ] as $text) {
            yield 'handle: '.$text => [$text, 'handle'];
        }
        foreach ([
            'https://example.com/sale', 'http://x.co', 'www.example.com', 'See example.com', 'Photos on facebook.com/groups/x', 'bit.ly/abc',
            'shop at janes-finds.shop', 'visit janesfinds dot com', 'EXAMPLE.ORG', 'paypal.me/jane', 'more at maple-sale.net!', 'write to jane at example.com',
        ] as $text) {
            yield 'url: '.$text => [$text, 'url'];
        }
    }

    #[DataProvider('found')]
    public function testContactDetailsAreFound(string $text, string $kind): void
    {
        self::assertSame($kind, ContactDetails::find($text), $text);
    }

    /** @return iterable<string, array{string}> ordinary notes */
    public static function ordinary(): iterable
    {
        foreach ([
            'Sat 8-2', '$5 bags', '2-for-1', 'items 1-100', 'No early birds before 8:00', 'Everything must go!', 'Baby clothes 0-24 months',
            "Kids' sizes 2T-5T", 'Bikes 20, 24 and 26 inch', 'Baseball cards 1985 1986 1987', 'Open 8:00-14:00', 'Sat 4/10 8 AM–2 PM, Sun 4/11 9–1',
            'Fill a bag for $10', 'Venmo accepted', 'We take Venmo, Zelle and cash', 'Cash app OK', 'PayPal or card welcome', 'Venmo is fine',
            'Zelle too.', 'Look at the lamps', 'Meet at the corner. Great deals', 'Starts at 8.30', 'Prices 50% off after noon', 'Lots of toys @ the garage',
            'Furniture, tools & more', 'Year 2026 sale!', 'Room 12-B', '3 families, 1 driveway', 'Sizes 10 12 14 16', 'e.g. lamps, i.e. lighting',
            'Sale @8am sharp', 'Dates 2027-04-10 and 2027-04-11', '8am-12pm', 'The 1-2-3 sale', '100s of books', 'Free stuff at the curb',
            'Open Sat 8–2 · Sun 9–1', 'Lamps at 50% off', 'DVDs, CDs, games', 'Two families. No. 5 is the blue house', 'Prices from $1 to $20',
            'Half price after 12:30', 'Toddler bed 1 of 2', 'Garage, porch and driveway', 'Back door is 2nd on the left', "Pay with cash, card or apps",
            "Tools: drills, saws, 3/8\" sockets", 'Ends at 1 PM', 'U.S. coins and stamps', '   ',
        ] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('ordinary')]
    public function testOrdinaryNotesPass(string $text): void
    {
        self::assertNull(ContactDetails::find($text), $text);
    }
}
