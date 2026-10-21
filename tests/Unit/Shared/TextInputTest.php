<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\TextInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: input sanitization review (2026-10-21). Free text (listing notes, review reasons,
 * organizer notes and settings) is escaped on output; on input it loses control characters and the
 * invisible Unicode characters that reorder or hide text (a right-to-left override can make a note or
 * an organizer email read differently from what it contains), is NFC-normalized and length-capped.
 */
#[CoversClass(TextInput::class)]
final class TextInputTest extends TestCase
{
    public function testLineRemovesControlAndInvisibleCharacters(): void
    {
        self::assertSame('Porch light on.', TextInput::line("Porch\u{200B} light\u{202E} on.\u{2066}", 100));
        self::assertSame('a b c', TextInput::line("a\tb\nc\x00", 100), 'controls become spaces, never glue words');
        self::assertSame('Caf'."\u{00E9}", TextInput::line("Cafe\u{0301}", 100), 'NFC: one é, however it was typed');
        self::assertSame('🎃 👨‍👩‍👧 ok', TextInput::line('🎃 👨‍👩‍👧 ok', 100), 'emoji (with zero-width joiners) are kept');
        self::assertSame('abc', TextInput::line('abcdef', 3));
        self::assertSame('', TextInput::line("\xC3\x28 broken UTF-8", 100));
        self::assertSame('', TextInput::line(['not', 'text'], 100));
        self::assertSame('12', TextInput::line(12, 100));
    }

    public function testParagraphsKeepLineBreaksOnly(): void
    {
        self::assertSame("First line\nSecond\n\nThird", TextInput::paragraphs("First\u{202E} line\r\nSecond\n\n\n\nThird\t", 100));
    }
}
