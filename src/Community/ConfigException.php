<?php

declare(strict_types=1);

namespace App\Community;

/**
 * @module Community/ConfigException
 * @description Invalid community configuration; lists every problem found.
 */
final class ConfigException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
