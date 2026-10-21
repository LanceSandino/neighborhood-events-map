<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * @module Shared/RegexQuoteEnvVarProcessor
 * @description `%env(regex_quote:…)%` escapes a value for use inside a regular expression, so the
 *     trusted_hosts pattern built from APP_BASE_URL's host matches that host literally ("." is a dot,
 *     not any character).
 */
final class RegexQuoteEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return preg_quote((string) $getEnv($name));
    }

    public static function getProvidedTypes(): array
    {
        return ['regex_quote' => 'string'];
    }
}
