<?php

declare(strict_types=1);

namespace App\Security;

/**
 * @module Security/EmailNormalizer
 * @description Lower-cases, trims and validates an email address (a plain local part and a real mail
 *     domain: no quoted local parts, IP literals or dot-less hosts); folds provider aliases for
 *     rate-limit keys; masks it for logs.
 */
final class EmailNormalizer
{
    public function normalize(string $email): ?string
    {
        $e = strtolower(trim($email));
        if ('' === $e || \strlen($e) > 254 || false === filter_var($e, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $at = (int) strrpos($e, '@');
        // Plain dot-atom local parts only: a quoted one such as "x<victim@evil.test>"@attacker.test is valid
        // RFC 5321, but mail libraries re-parse it as "name <address>" and deliver elsewhere, and characters
        // like % ? & = have routing or URL meaning. Real mailboxes use letters, digits and . _ + ' -
        if (!preg_match("~^[a-z0-9_+'-]+(?:\\.[a-z0-9_+'-]+)*$~", substr($e, 0, $at))) {
            return null;
        }
        $domain = substr($e, $at + 1);
        if (!preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$~', $domain)) {
            return null; // IP literals, dot-less or numeric hosts are never real recipients here
        }

        return $e;
    }

    /**
     * Rate-limit key for a normalized address: drops "+tag" suffixes and, for Gmail, dots, so aliases
     * of one mailbox share one budget. Never used as the delivery address.
     */
    public function throttleKey(string $normalized): string
    {
        [$local, $domain] = array_pad(explode('@', $normalized, 2), 2, '');
        $local = explode('+', $local, 2)[0];
        if (\in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
