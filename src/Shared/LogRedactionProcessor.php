<?php

declare(strict_types=1);

namespace App\Shared;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * @module Shared/LogRedactionProcessor
 * @description Removes one-time sign-in tokens (query parameter "t") and CSRF/token fields from every
 *     log record — messages and nested context/extra values — before any handler writes them.
 */
#[AsMonologProcessor]
final class LogRedactionProcessor implements ProcessorInterface
{
    private const PATTERN = '~([?&]t=|"t"\s*:\s*"|_csrf=)[A-Za-z0-9_\-%.]+~';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrub($record->message),
            context: self::scrubArray($record->context),
            extra: self::scrubArray($record->extra),
        );
    }

    /** Email addresses are reduced to the same "j***@example.org" form the app logs itself. */
    private const EMAIL = '~(?<![A-Za-z0-9._%+-])([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})~';

    public static function scrub(string $value): string
    {
        $value = preg_replace(self::PATTERN, '$1[redacted]', $value) ?? '[redacted]';

        return preg_replace(self::EMAIL, '$1***@$2', $value) ?? '[redacted]';
    }

    /** @param array<mixed> $values */
    private static function scrubArray(array $values): array
    {
        foreach ($values as $k => $v) {
            if (\is_string($v)) {
                $values[$k] = self::scrub($v);
            } elseif ($v instanceof \Stringable) {
                // e.g. the security token Symfony logs at sign-in, whose string form contains the email.
                $values[$k] = self::scrub((string) $v);
            } elseif (\is_array($v)) {
                $values[$k] = self::scrubArray($v);
            }
        }

        return $values;
    }
}
