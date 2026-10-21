<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: branding.show_locality shows "City, ST" under the community name (header and rail);
 * off by default; the header carries the Appearance button and the version appears once (footer).
 * Titles, brand, footer, meta description and emails name the community's event (event.name, default
 * Halloween), never a fixed holiday.
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

    public function testThePageNamesTheCommunitysEventNotAFixedHoliday(): void
    {
        $this->configureCommunity(['event' => ['name' => 'Spring Fair']]);
        $crawler = $this->client->request('GET', '/');
        $html = (string) $crawler->html();
        self::assertSame('Testville Spring Fair', $crawler->filter('title')->text());
        self::assertStringContainsString('Testville<br>Spring Fair', $html, 'sidebar brand');
        self::assertStringContainsString('Testville Spring Fair · ', $crawler->filter('.footer-id')->text());
        self::assertStringStartsWith('Testville Spring Fair: ', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        $chrome = implode(' ', $crawler->filter('title, .brand, .footer-id')->each(static fn ($n) => $n->text())).' '.$crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringNotContainsString('Halloween', $chrome, 'no fixed holiday in titles, brand, footer or description');
        self::assertSame('Spring Fair', $this->json('GET', '/api/community')['event']['name']);
        foreach (['/about' => 'About · Testville Spring Fair', '/privacy' => 'Privacy · Testville Spring Fair', '/terms' => 'Terms of use · Testville Spring Fair', '/login' => 'Sign in · Testville Spring Fair'] as $path => $title) {
            self::assertSame($title, $this->client->request('GET', $path)->filter('title')->text(), $path);
        }

        $this->requestLink('fair@example.test');
        $messages = self::getMailerMessages();
        $mail = end($messages);
        \assert($mail instanceof \Symfony\Component\Mime\Email);
        self::assertSame('Your Testville Spring Fair sign-in link', $mail->getSubject());
        self::assertStringNotContainsString('HALLOWEEN', (string) $mail->getHtmlBody());
    }

    public function testSettingsSavedBeforeTheEventNameExistedStillSayHalloween(): void
    {
        $this->configureCommunity();
        $this->db()->executeStatement("UPDATE community_settings SET settings = settings #- '{event,name}'");
        $this->repo()->reset();
        self::assertSame('Testville Halloween', $this->client->request('GET', '/')->filter('title')->text());
        self::assertSame('Halloween', $this->json('GET', '/api/community')['event']['name']);
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('boss@example.test', TRUE, now())");
        $this->signIn('boss@example.test');
        self::assertSame('Halloween', $this->json('GET', '/api/admin/community')['settings']['event']['name'], 'the console form shows it too');
    }
}
