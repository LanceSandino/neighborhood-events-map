<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: branding.show_locality shows "City, ST" under the community name (header and rail);
 * off by default; the header carries the Appearance button and the version appears once (footer).
 */
final class HeaderBrandingTest extends AppTestCase
{
    public function testCityUnderTheNameIsAnOption(): void
    {
        $this->configureCommunity();
        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringNotContainsString('class="crumb-sub"', $html);
        self::assertStringContainsString('Community 2026', $html);
        self::assertStringContainsString('aria-label="Appearance and accessibility"', $html);
        self::assertSame(1, substr_count($html, 'data-whats-new>'), 'the version link appears once');

        $this->configureCommunity(['branding' => ['show_locality' => true]]);
        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringContainsString('<small class="crumb-sub">Testtown, PA</small>', $html);
        self::assertStringContainsString('Testtown, PA · 2026', $html);
        self::assertTrue($this->json('GET', '/api/community')['branding']['show_locality']);
    }
}
