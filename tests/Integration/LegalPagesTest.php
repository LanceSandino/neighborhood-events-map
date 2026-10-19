<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: privacy and accessibility best practice — public pages set no cookies and load no
 * third-party scripts; a privacy notice, terms of use and accessibility statement are linked from
 * every page; the organizer's contact link is offered for requests.
 */
final class LegalPagesTest extends AppTestCase
{
    public function testPolicyPagesExistAndDescribeWhatTheSiteDoes(): void
    {
        $this->configureCommunity(['community' => ['contact_url' => 'mailto:organizer@example.test']]);
        $pages = [
            '/privacy' => ['no advertising, no analytics and no tracking', 'local storage', 'OpenStreetMap', 'strictly necessary session cookie', 'btnClearLocal'],
            '/terms' => ['self-reported', 'not walking directions', 'without warranties'],
            '/accessibility' => ['WCAG', 'Colour is never the only signal', 'keyboard'],
        ];
        foreach ($pages as $path => $needles) {
            $html = (string) $this->client->request('GET', $path)->html();
            self::assertSame(200, $this->code(), $path);
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $html, "$path mentions $needle");
            }
            self::assertStringContainsString('href="mailto:organizer@example.test"', $html, "$path offers the organizer contact");
        }
    }

    public function testEveryPublicPageLinksThePoliciesAndSetsNoCookies(): void
    {
        $this->configureCommunity();
        foreach (['/', '/login', '/privacy', '/terms', '/accessibility', '/api/community', '/api/homes', '/theme.css'] as $path) {
            $this->client->getCookieJar()->clear();
            $this->client->request('GET', $path);
            self::assertSame(200, $this->code(), $path);
            self::assertSame([], $this->client->getResponse()->headers->getCookies(), "$path sets no cookie for a visitor");
            if (!str_starts_with($path, '/api') && '/theme.css' !== $path) {
                $html = (string) $this->client->getResponse()->getContent();
                foreach (['href="/privacy"', 'href="/terms"', 'href="/accessibility"'] as $link) {
                    self::assertStringContainsString($link, $html, "$path links $link");
                }
                self::assertDoesNotMatchRegularExpression('~<script[^>]+src="https?://~i', $html, "$path loads no third-party script");
            }
        }
    }
}
