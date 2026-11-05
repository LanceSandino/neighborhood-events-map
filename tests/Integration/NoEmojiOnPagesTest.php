<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\CustomHalloweenCommunity;
use App\Tests\Support\Glyphs;

/**
 * @module tests/Integration/NoEmojiOnPagesTest
 * @description The pages people see draw no emoji (D-20261105-01), for every event type and for a page with no event: the
 *   map, About, sign-in, the legal pages, What's new, past events, My house and the organizer console, whole documents
 *   with their embedded data. Words an organizer wrote are theirs: a stored headline that starts with an emoji is shown
 *   exactly as written (never stripped), and it is the only glyph on the page. Text glyphs replaced by icons keep an
 *   accessible name, and icons are aria-hidden.
 * @dependencies tests/Support/AppTestCase, tests/Support/CustomHalloweenCommunity, tests/Support/Glyphs
 */
final class NoEmojiOnPagesTest extends AppTestCase
{
    use CustomHalloweenCommunity;

    private const PAGES = ['/', '/about', '/login', '/privacy', '/terms', '/accessibility', '/changelog', '/past-events'];

    public function testNoPageOfAnyEventDrawsAnEmoji(): void
    {
        $ids = $this->customHalloweenCommunity();
        // The organizer's words from before 0.19 are text to keep, not something this test may look at: plain words here.
        $this->withPlainOrganizerWords($ids['halloween']);
        foreach ($ids as $name => $id) {
            foreach (self::PAGES as $path) {
                $this->client->request('GET', $path.'?e='.$id);
                self::assertSame(200, $this->code(), "$name $path");
                self::assertSame([], Glyphs::in((string) $this->client->getResponse()->getContent()), "$name $path draws only its own art");
            }
        }
        $this->signIn('no-emoji-resident@example.test');
        foreach ($ids as $name => $id) {
            $this->client->request('GET', '/me?e='.$id);
            self::assertSame(200, $this->code());
            self::assertSame([], Glyphs::in((string) $this->client->getResponse()->getContent()), "$name My house");
        }
    }

    public function testTheOrganizerConsoleDrawsNoEmoji(): void
    {
        $ids = $this->customHalloweenCommunity();
        $this->withPlainOrganizerWords($ids['halloween']);
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('no-emoji-organizer@example.test', TRUE, NOW())");
        $this->signIn('no-emoji-organizer@example.test');
        foreach (['/admin', '/admin/events', '/admin/community', '/admin/community/map', '/admin/addresses', '/admin/disputes', '/admin/audit'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(200, $this->code(), $path);
            self::assertSame([], Glyphs::in((string) $this->client->getResponse()->getContent()), $path);
        }
    }

    /** Stored organizer text is left alone (the coordinator's review: a live headline starts with an emoji). */
    public function testAnOrganizersOwnEmojiIsShownAsWrittenAndIsTheOnlyOne(): void
    {
        $ids = $this->customHalloweenCommunity();
        $this->db()->executeStatement("UPDATE event_occurrences SET settings = jsonb_set(settings, '{branding}', CAST(:b AS JSONB)) WHERE id = :id",
            ['b' => json_encode(['headline' => '👻 Tiny ghosts.', 'brand_emoji' => '🎃'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'id' => $ids['halloween']]);
        $this->repo()->reset();
        $page = $this->client->request('GET', '/?e='.$ids['halloween']);
        self::assertSame('👻 Tiny ghosts. Big adventures.', $page->filter('section.intro h1')->text(), 'the organizer\'s words as written');
        self::assertSame(['👻'], Glyphs::in((string) $this->client->getResponse()->getContent()), 'their emoji is the only one on the page: a stored emoji setting is not drawn');
    }

    public function testControlsThatWereGlyphsKeepTheirNamesAndIconsAreHidden(): void
    {
        $ids = $this->customHalloweenCommunity();
        $this->withPlainOrganizerWords($ids['halloween']);
        $page = $this->client->request('GET', '/?e='.$ids['lights']);
        foreach (['#btnFit', '#btnZoomIn', '#btnZoomOut', '#btnLocate', '[data-view="cards"]', '[data-view="compact"]', '#toTop', '.top-icon', '.close-modal'] as $selector) {
            $control = $page->filter($selector)->first();
            self::assertNotSame('', (string) $control->attr('aria-label'), "$selector keeps its accessible name");
            self::assertSame(1, $control->filter('svg.icon')->count(), "$selector draws an icon");
        }
        $page->filter('svg')->each(static function ($svg): void {
            self::assertSame('true', $svg->attr('aria-hidden'), 'art is decorative');
            self::assertSame('false', $svg->attr('focusable'));
        });
    }

    private function withPlainOrganizerWords(int $halloween): void
    {
        $this->db()->executeStatement("UPDATE event_occurrences SET settings = settings #- '{branding,headline}' #- '{branding,brand_emoji}' WHERE id = :id", ['id' => $halloween]);
        $this->repo()->reset();
    }
}
