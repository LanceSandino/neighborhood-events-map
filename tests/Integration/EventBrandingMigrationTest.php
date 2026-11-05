<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\CustomHalloweenCommunity;
use App\Tests\Support\RunsMigrations;

/**
 * @module tests/Integration/EventBrandingMigrationTest
 * @description Per-event branding (migrations/Version20261031000004, ADR 0012) on the upgrade paths production takes.
 *   From a 0.15 database whose community kept its own Halloween branding with Halloween, Christmas lights and a yard
 *   sale open: every Halloween event takes the custom values (only those: built-in defaults stay defaults), the
 *   Halloween pages render as 0.15.0-beta.1 rendered them with the drawn art of 0.19 in place of its emoji
 *   (tests/fixtures/golden/0.19.0-beta.1/branding-halloween.json, written from the same data; NHM_WRITE_GOLDEN=1 rewrites
 *   it after a deliberate change), the lights and the sale show their type's own words and colours, and
 *   the stored community keys the 0.9 image reads still hold the Halloween branding; down restores the community keys
 *   from the Halloween the 0.9 image shows (the newest open or closed one) and removes the events' branding, and up
 *   again gives the same result. From a 0.9 database
 *   (production today) every migration in order gives the open Halloween its custom branding and keeps 0.9's keys.
 * @dependencies tests/Support/AppTestCase, tests/Support/RunsMigrations, tests/Support/CustomHalloweenCommunity
 */
final class EventBrandingMigrationTest extends AppTestCase
{
    use CustomHalloweenCommunity;
    use RunsMigrations;

    private const V015 = 'DoctrineMigrations\Version20261031000003';
    private const V09 = 'DoctrineMigrations\Version20261021000001';
    private const GOLDEN = __DIR__.'/../fixtures/golden/0.19.0-beta.1/branding-halloween.json';
    /** The event branding the migration gives the Halloween events: the custom values of CUSTOM_BRANDING and the About text. */
    private const MOVED = ['headline' => '👻 Tiny ghosts.', 'intro' => 'Grab a bucket, follow the porch lights and find the candy stops of Fernbrook Glen — skip the scares if you like.',
        'primary_color' => '#D9652B', 'about' => self::CUSTOM_ABOUT];

    public function testA015CommunityKeepsItsHalloweenBrandingOnHalloweenOnly(): void
    {
        try {
            $this->migrateTo(self::V015);
            $ids = $this->customHalloweenCommunity();
            // A Halloween of last year (archived) and next year's draft take it too; another type's draft does not.
            $db = $this->db();
            $archived = (int) $db->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, archived_at)
                VALUES ('halloween', 'Halloween', '2025-10-31', '2025-10-31', '17:00', '21:00', 'archived', NOW(), NOW()) RETURNING id");
            $own = (int) $db->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, settings)
                VALUES ('halloween', 'Halloween 2027', '2027-10-31', '2027-10-31', '17:00', '21:00', 'draft', NOW(), '{\"branding\": {\"headline\": \"Its own.\"}}') RETURNING id");
            self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM event_occurrences WHERE settings->'branding' IS NOT NULL"), '0.15 data: only the hand-made draft names a branding');
            $stored = $this->storedSettings();

            $this->migrateTo('latest');
            $this->repo()->reset();
            $this->assertUpgraded($ids + ['archived' => $archived, 'own' => $own], $stored);

            $this->migrateTo(self::V015);
            $down = $this->storedSettings();
            self::assertSame($stored['branding'], $down['branding'], 'down: the community keys are the newest Halloween\'s branding, as 0.15 kept them');
            self::assertSame($stored['community'], $down['community'], 'and its About text; no general About key');
            self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM event_occurrences WHERE settings->'branding' IS NOT NULL"), 'down: no event keeps a branding of its own');
        } finally {
            $this->migrateTo('latest');
        }
        $this->repo()->reset();
        self::assertSame(self::sorted(self::MOVED), $this->branding($ids['halloween']), 'up again: the same result');
        self::assertNull($this->branding($ids['lights']));
    }

    public function testA09InstallUpgradesStraightToPerEventBrandingAndKeeps09sKeys(): void
    {
        $this->configureCommunity(['community' => ['id' => 'fernbrook-glen', 'name' => 'Fernbrook Glen', 'locality' => 'Larkfield', 'region' => 'OR']]);
        try {
            $this->migrateTo(self::V09);
            self::assertFalse($this->db()->fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name = 'event_occurrences'"), 'the 0.9 schema has no events');
            $this->storeLegacyBranding(self::CUSTOM_BRANDING, self::CUSTOM_ABOUT);
        } finally {
            $this->migrateTo('latest');
        }
        $this->repo()->reset();
        $db = $this->db();
        self::assertSame([['halloween', 'open']], array_map(static fn (array $r) => [$r['type'], $r['state']], $db->fetchAllAssociative('SELECT type, state FROM event_occurrences')));
        $halloween = (int) $db->fetchOne('SELECT id FROM event_occurrences');
        self::assertSame(self::sorted(self::MOVED), $this->branding($halloween), 'every migration in order: the open Halloween has the custom branding');
        $golden = json_decode((string) file_get_contents(self::GOLDEN), true, 64, \JSON_THROW_ON_ERROR);
        $home = $this->client->request('GET', '/');
        self::assertSame($golden['home']['intro'], self::stable($home->filter('section.intro')->outerHtml()));
        self::assertSame($golden['home']['brand'], self::stable($home->filter('a.brand')->outerHtml()));
        $this->client->request('GET', '/theme.css');
        self::assertSame($golden['theme_css'], (string) $this->client->getResponse()->getContent());
        $stored = $this->storedSettings();
        self::assertSame(self::sorted(self::CUSTOM_BRANDING), self::sorted(array_intersect_key($stored['branding'], self::CUSTOM_BRANDING)), 'the keys the 0.9 image reads keep the Halloween branding');
        self::assertSame(self::CUSTOM_ABOUT, $stored['community']['about']);
    }

    /** Rendered markup without the asset fingerprint, which changes with every file. */
    private static function stable(string $html): string
    {
        return (string) preg_replace('~/assets/v[A-Za-z0-9._+-]+/~', '/assets/vX/', $html);
    }

    /**
     * The fixture of the upgraded Halloween's pages, written from this data with NHM_WRITE_GOLDEN=1 after a deliberate change.
     *
     * @return array<string,mixed>
     */
    private function renderedGolden(): array
    {
        if ('1' === getenv('NHM_WRITE_GOLDEN')) {
            $home = $this->client->request('GET', '/');
            $golden = ['home' => ['title' => $home->filter('title')->text(), 'description' => $home->filter('meta[name="description"]')->attr('content'),
                'brand' => self::stable($home->filter('a.brand')->outerHtml()), 'intro' => self::stable($home->filter('section.intro')->outerHtml()), 'side_panel' => $home->filter('.side-panel')->text()]];
            $about = $this->client->request('GET', '/about');
            $golden['about'] = ['title' => $about->filter('title')->text(), 'eyebrow' => self::stable($about->filter('article.about .eyebrow')->outerHtml()),
                'lead' => $about->filter('article.about .legal-lead')->outerHtml(), 'about_section' => $about->filter('#aboutTitle')->closest('section')->outerHtml()];
            $this->client->request('GET', '/theme.css');
            $golden['theme_css'] = (string) $this->client->getResponse()->getContent();
            $api = $this->json('GET', '/api/community');
            $golden['api'] = ['branding' => $api['branding'], 'about' => $api['community']['about'], 'subtitle' => $api['community']['subtitle']];
            @mkdir(\dirname(self::GOLDEN), 0o755, true);
            file_put_contents(self::GOLDEN, json_encode($golden, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
        }

        return json_decode((string) file_get_contents(self::GOLDEN), true, 64, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,int>   $ids    the events
     * @param array<string,mixed> $stored the stored settings before the upgrade
     */
    private function assertUpgraded(array $ids, array $stored): void
    {
        foreach (['halloween', 'archived'] as $name) {
            self::assertSame(self::sorted(self::MOVED), $this->branding($ids[$name]), "$name Halloween: the custom values and the About text; Halloween's emoji, accent part and plum stay defaults");
        }
        self::assertSame(['headline' => 'Its own.'], $this->branding($ids['own']), 'a Halloween that already has a branding keeps it');
        self::assertNull($this->branding($ids['lights']), 'the lights take nothing of Halloween\'s');
        self::assertNull($this->branding($ids['sale']));
        self::assertSame($stored, $this->storedSettings(), 'the community keys stay as the 0.9 image reads them');

        // Halloween renders as 0.15.0-beta.1 did, with the drawn art of 0.19.
        $golden = $this->renderedGolden();
        $home = $this->client->request('GET', '/');
        self::assertSame($golden['home']['title'], $home->filter('title')->text());
        self::assertSame($golden['home']['description'], $home->filter('meta[name="description"]')->attr('content'));
        self::assertSame($golden['home']['brand'], self::stable($home->filter('a.brand')->outerHtml()));
        self::assertSame($golden['home']['intro'], self::stable($home->filter('section.intro')->outerHtml()));
        self::assertSame($golden['home']['side_panel'], $home->filter('.side-panel')->text());
        $about = $this->client->request('GET', '/about');
        self::assertSame($golden['about']['title'], $about->filter('title')->text());
        self::assertSame($golden['about']['eyebrow'], self::stable($about->filter('article.about .eyebrow')->outerHtml()));
        self::assertSame($golden['about']['lead'], $about->filter('article.about .legal-lead')->outerHtml());
        self::assertSame($golden['about']['about_section'], $about->filter('#aboutTitle')->closest('section')->outerHtml());
        $this->client->request('GET', '/theme.css');
        self::assertSame($golden['theme_css'], (string) $this->client->getResponse()->getContent());
        $api = $this->json('GET', '/api/community');
        self::assertSame($golden['api'], ['branding' => $api['branding'], 'about' => $api['community']['about'], 'subtitle' => $api['community']['subtitle']], '/api/community: same values in the same order');

        // The lights and the sale show their own type's branding and About text.
        foreach (['lights' => ['Holiday nights.', 'Bright lights.', '#38597F', 'holiday light displays'], 'sale' => ['Good finds.', 'Close to home.', '#178D80', 'holding a sale']] as $name => [$headline, $accent, $color, $words]) {
            $c = $this->json('GET', '/api/community?e='.$ids[$name]);
            self::assertSame([$headline, $accent, $color], [$c['branding']['headline'], $c['branding']['headline_accent'], $c['branding']['primary_color']], $name);
            self::assertSame('', $c['community']['about'], "$name: no About text of its own and no general one");
            self::assertStringContainsString($words, (string) $this->client->request('GET', '/about?e='.$ids[$name])->filter('#aboutTitle')->closest('section')->text(), "$name: the type's built-in About text");
            $this->client->request('GET', '/theme.css?e='.$ids[$name]);
            self::assertStringContainsString('--brand:'.$color, (string) $this->client->getResponse()->getContent());
        }
    }

    /** @return array<string,mixed>|null an event's stored branding */
    private function branding(int $event): ?array
    {
        $json = $this->db()->fetchOne("SELECT settings->'branding' FROM event_occurrences WHERE id = ?", [$event]);

        return null === $json || false === $json ? null : self::sorted(json_decode((string) $json, true, 8, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $a
     *
     * @return array<string,mixed> by key (JSONB keeps its own key order)
     */
    private static function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    /** @return array<string,mixed> */
    private function storedSettings(): array
    {
        return json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings WHERE id = 1'), true, 64, \JSON_THROW_ON_ERROR);
    }
}
