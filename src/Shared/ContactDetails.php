<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * @module Shared/ContactDetails
 * @description Finds contact details in public free text (garage-sale notes, D-20261021-23): phone numbers in their
 *     usual shapes (spaced, dotted, dashed, parenthesized, with a country code, ten digits in a row, seven-digit local
 *     numbers with a dash or dot whose exchange code starts with 2–9, as every real one does), email addresses (also
 *     "name (at) domain" and "name at domain dot com"), payment handles ($cashtags, @handles, a payment app followed
 *     by a username) and web addresses (with or without a scheme, "name dot com"). It only reports what it found;
 *     the caller refuses the text and nothing is stored. Text is checked after Unicode compatibility folding, so
 *     full-width digits and at signs count. Numbers written as words ("five five five") are out of scope; times,
 *     prices, sizes, ranges and dates are not contact details.
 *
 * @dependencies none
 */
final class ContactDetails
{
    public const PHONE = 'phone';
    public const EMAIL = 'email';
    public const HANDLE = 'handle';
    public const URL = 'url';

    /** Top-level domains that mark a bare "name.tld" as a web address (a short list keeps "e.g." and "8.30" out). */
    private const TLDS = 'com|net|org|edu|gov|io|co|us|me|ly|gl|gg|tv|info|biz|app|dev|shop|store|site|online|xyz|link|page|live|club|social|ca|uk|de|fr|au|nz|eu|ie|mx|es|it|nl|be|ch';
    private const AT = '(?:\(\s*at\s*\)|\[\s*at\s*\]|\{\s*at\s*\})';
    private const DOT = '(?:\(\s*dot\s*\)|\[\s*dot\s*\]|\{\s*dot\s*\})';
    private const PAYMENT_APPS = 'venmo|pay\s?pal|zelle|cash\s?-?app';
    /** Words that may follow a payment app's name without being a username ("Venmo accepted", "Zelle or cash"). */
    private const PLAIN_WORDS = ['accepted', 'accept', 'accepting', 'ok', 'okay', 'welcome', 'and', 'or', 'too', 'only', 'preferred', 'also', 'works', 'work',
        'payment', 'payments', 'please', 'available', 'app', 'apps', 'cash', 'card', 'cards', 'credit', 'debit', 'apple', 'google', 'pay', 'fine', 'if', 'for',
        'with', 'on', 'the', 'a', 'an', 'here', 'via', 'we', 'no', 'not', 'yes', 'sure', 'after', 'before', 'both', 'all', 'transfer', 'transfers', 'venmo',
        'zelle', 'paypal', 'are', 'will', 'can', 'users', 'etc', 'plus', 'as', 'is'];
    /** Words between a payment app and a username ("Venmo me at …", "Zelle is …"), skipped before the check. */
    private const CONNECTORS = '(?:me|is|at|to|id|user|username|handle|account|name)';

    /** @return string|null the kind of contact detail found first (email, phone, handle, url), or null */
    public static function find(string $text): ?string
    {
        $t = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $t = \is_string($t) ? $t : $text;
        if ('' === trim($t)) {
            return null;
        }

        return match (true) {
            self::hasEmail($t) => self::EMAIL,
            self::hasPhone($t) => self::PHONE,
            self::hasHandle($t) => self::HANDLE,
            self::hasUrl($t) => self::URL,
            default => null,
        };
    }

    private static function hasEmail(string $t): bool
    {
        $user = '[\p{L}\p{N}._%+\-]+';
        $label = '[\p{L}\p{N}\-]+';
        // a plain address (local part, at sign, domain, top-level domain); with spaces around the signs only for a known top-level domain ("us @thrifty.jane" is a handle)
        if (preg_match('~'.$user.'@'.$label.'(?:\.'.$label.')*\.\p{L}{2,24}~u', $t)
            || preg_match('~'.$user.'\s*@\s*'.$label.'(?:\s*\.\s*'.$label.')*\s*\.\s*(?:'.self::TLDS.')\b~iu', $t)) {
            return true;
        }
        // name (at) domain.tld / name [at] domain [dot] tld
        if (preg_match('~'.$user.'\s*'.self::AT.'\s*'.$label.'(?:(?:\s*'.self::DOT.'\s*|\s+dot\s+|\.)'.$label.')*?(?:\s*'.self::DOT.'\s*|\s+dot\s+|\.)(?:'.self::TLDS.')\b~iu', $t)) {
            return true;
        }

        // name at domain dot tld (a spelled "at" needs a spelled "dot": "shop at maple.com" is a web address)
        return 1 === preg_match('~'.$user.'\s+at\s+'.$label.'(?:(?:\s*'.self::DOT.'\s*|\s+dot\s+)'.$label.')*?(?:\s*'.self::DOT.'\s*|\s+dot\s+)(?:'.self::TLDS.')\b~iu', $t);
    }

    private static function hasPhone(string $t): bool
    {
        $sep = '\s*[\s.\-]?\s*';
        // North American shapes: (555) 123-4567, 555-123-4567, 555.123.4567, 555 123 4567, 5551234567, +1 / 1- first
        if (preg_match('~(?<![\d$])(?:\+?\s*1'.$sep.')?(?:\(\s*\d{3}\s*\)|\d{3})'.$sep.'\d{3}'.$sep.'\d{4}(?!\d)~u', $t)) {
            return true;
        }
        // seven-digit local numbers with a dash or a dot: 555-1234. Every real exchange code starts with 2–9 (NANP), so a
        // range such as "items 100-1000" is not a number
        if (preg_match('~(?<![\d$])[2-9]\d{2}\s*[.\-]\s*\d{4}(?!\d)~u', $t)) {
            return true;
        }
        // international: a plus, a country code and enough digits for a number (+44 20 7946 0958)
        if (preg_match_all('~\+\s*\d{1,3}(?:[\s.\-]*\(?\d{1,4}\)?){2,5}~u', $t, $m)) {
            foreach ($m[0] as $candidate) {
                if (preg_match_all('~\d~', $candidate) >= 8) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasHandle(string $t): bool
    {
        // $cashtag (a dollar sign followed by a letter, so "$5" is a price)
        if (preg_match('~(?<![\p{L}\p{N}])\$[\p{L}_][\p{L}\p{N}_\-]{1,19}~u', $t)) {
            return true;
        }
        // @handle (not an email, not "@ the", not a time like "@8am")
        if (preg_match_all('~(?<![\p{L}\p{N}._%+\-])@([\p{L}\p{N}_][\p{L}\p{N}_.\-]{1,29})~u', $t, $m)) {
            foreach ($m[1] as $h) {
                if (!preg_match('~^\d{1,2}(?::\d{2})?(?:am|pm)?$~i', rtrim($h, '.-'))) {
                    return true;
                }
            }
        }
        // a payment app followed by a username: "Venmo: jane-doe", "Zelle me at jane", "PayPal JaneDoe"
        if (preg_match_all('~\b(?:'.self::PAYMENT_APPS.')\b(.{0,60})~iu', $t, $m)) {
            foreach ($m[1] as $tail) {
                if (self::namesAUser($tail)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether the words after a payment app's name start with a username. */
    private static function namesAUser(string $tail): bool
    {
        $rest = preg_replace('~^\s*[:=@\-–—]\s*~u', ' ', $tail) ?? $tail;
        if (!preg_match('~^\s~u', $rest)) {
            return false; // punctuation right after the name ("Venmo, Zelle", "paypal.me/…" is a web address)
        }
        $rest = preg_replace('~^(?:\s*\b'.self::CONNECTORS.'\b\s*[:=@]?){1,3}~iu', ' ', $rest) ?? $rest;
        if (!preg_match('~^\s*[@$]?([\p{L}\p{N}][\p{L}\p{N}_.\-]*)~u', $rest, $w)) {
            return false;
        }

        return !\in_array(mb_strtolower(rtrim($w[1], '.-')), self::PLAIN_WORDS, true);
    }

    private static function hasUrl(string $t): bool
    {
        if (preg_match('~\b(?:https?://|www\.)\S+~iu', $t)) {
            return true;
        }
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}\-]*[\p{L}\p{N}])?';
        if (preg_match('~(?<![@\p{L}\p{N}.\-])'.$label.'(?:\.'.$label.')*\.(?:'.self::TLDS.')(?![\p{L}\p{N}\-])~iu', $t)) {
            return true;
        }

        // name dot com
        return 1 === preg_match('~[\p{L}\p{N}\-]+\s*(?:'.self::DOT.'|\s+dot\s+)\s*(?:'.self::TLDS.')\b~iu', $t);
    }
}
