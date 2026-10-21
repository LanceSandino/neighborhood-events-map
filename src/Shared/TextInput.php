<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * @module Shared/TextInput
 * @description One cleaning rule for free text people type (listing notes, review reasons, organizer
 *     notes and community settings). Output is always escaped; on input the text must be valid UTF-8,
 *     is NFC-normalized, loses control characters and the invisible Unicode characters that reorder or
 *     hide text (bidirectional overrides, zero-width spaces, BOM, soft hyphen), and is length-capped.
 *     Zero-width joiners stay because emoji sequences need them. Addresses have a stricter rule
 *     (Address/AddressNormalizer: plain ASCII only).
 */
final class TextInput
{
    /** Invisible characters that can make text read differently from what it contains. */
    private const INVISIBLE = '~[\x{00AD}\x{061C}\x{180E}\x{200B}\x{200C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]~u';

    /** Single line: every control character (newlines and tabs too) becomes a space. */
    public static function line(mixed $value, int $max): string
    {
        $s = self::base($value);

        return mb_substr(trim(preg_replace('~\p{Cc}~u', ' ', $s) ?? ''), 0, $max);
    }

    /** Paragraphs: line breaks are kept (at most one blank line in a row); other controls become spaces. */
    public static function paragraphs(mixed $value, int $max): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", self::base($value));
        $s = preg_replace(['~[^\P{Cc}\n]~u', '~[ ]+\n~', '~\n{3,}~'], [' ', "\n", "\n\n"], $s) ?? '';

        return mb_substr(trim($s), 0, $max);
    }

    private static function base(mixed $value): string
    {
        $s = \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : '';
        if ('' === $s || !mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        $s = \Normalizer::normalize($s, \Normalizer::FORM_C);

        return preg_replace(self::INVISIBLE, '', false === $s ? '' : $s) ?? '';
    }
}
