<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/WhatsNewTest
 * @description The public "What's new" (the dialog on every page and /changelog) is for residents and organizers: each
 *   release shown opens with a plain summary, and none of the words of whoever runs the site (rollback, migration,
 *   image, pixel, the 0.9 release, the bridge, the schema) appears in it — those notes live in
 *   docs/runbooks/upgrade-rollback.md and CHANGES.md (D-20261031-50).
 * @dependencies tests/Support/AppTestCase
 */
final class WhatsNewTest extends AppTestCase
{
    private const OPERATOR_WORDS = '~\b(roll(?:ed)?[- ]?backs?|migrations?|migrat\w*|images?|pixels?|px|bridge[sd]?|schemas?)\b|\b0\.9\b|\bv?0\.9\.0|/api/|bin/|`~i';

    public function testWhatsNewShowsPlainNotesWithoutOperatorWords(): void
    {
        $this->configureCommunity();
        $dialog = $this->client->request('GET', '/')->filter('#whatsNew');
        self::assertCount(3, $dialog->filter('details.release'), 'the dialog shows the three newest releases');
        self::assertCount(3, $dialog->filter('.release-summary'), 'each opens with a plain summary');
        $page = $this->client->request('GET', '/changelog')->filter('article.whats-new-page');
        self::assertCount(6, $page->filter('details.release'));
        self::assertCount(6, $page->filter('.release-summary'));
        foreach (['dialog' => $dialog->text(), 'page' => $page->text()] as $where => $text) {
            preg_match_all(self::OPERATOR_WORDS, $text, $m);
            self::assertSame([], array_values(array_unique($m[0])), "What's new ($where) has no operator words");
        }
    }

    public function testTheCheckFindsOperatorWords(): void
    {
        foreach (['After an image-only rollback', 'one new database migration', '40 pixels high', 'back to 0.9', 'the legacy bridge', 'schema version', 'GET /api/events'] as $text) {
            self::assertSame(1, preg_match(self::OPERATOR_WORDS, $text), $text);
        }
        self::assertSame(0, preg_match(self::OPERATOR_WORDS, 'Each event has its own look; version 0.16 and 0.15 notes, a logo picture.'));
    }
}
