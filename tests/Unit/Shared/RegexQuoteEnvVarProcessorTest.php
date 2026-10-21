<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\RegexQuoteEnvVarProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: audit run-1 hardening note — trusted_hosts was built from APP_BASE_URL's host without
 * escaping, so "map.example.org" also trusted "mapXexample.org".
 */
#[CoversClass(RegexQuoteEnvVarProcessor::class)]
final class RegexQuoteEnvVarProcessorTest extends TestCase
{
    public function testHostIsMatchedLiterally(): void
    {
        $quoted = (new RegexQuoteEnvVarProcessor())->getEnv('regex_quote', 'APP_HOST', static fn () => 'map.example.org');
        self::assertSame('map\.example\.org', $quoted);
        self::assertSame(1, preg_match('{^'.$quoted.'$}i', 'map.example.org'));
        self::assertSame(0, preg_match('{^'.$quoted.'$}i', 'mapXexample.org'));
        self::assertSame(['regex_quote' => 'string'], RegexQuoteEnvVarProcessor::getProvidedTypes());
    }
}
