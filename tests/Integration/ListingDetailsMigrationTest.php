<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @module tests/Integration/ListingDetailsMigrationTest
 * @description listings.details (D-20261031-03): every write keeps the legacy Halloween columns and `details`
 *   equal; rows written the 0.9 way (columns only, as after an image-only rollback) get their details from the
 *   columns; the public map reads `details`; and the migration goes down and up again, backfilling every row.
 * @dependencies tests/Support/AppTestCase, migrations/Version20261031000001
 */
final class ListingDetailsMigrationTest extends AppTestCase
{
    private const PREVIOUS = 'DoctrineMigrations\Version20261021000001';
    private const FROM_COLUMNS = "jsonb_build_object('candy', candy, 'nonfood', nonfood, 'adult_drinks', adult_drinks, 'low_noise', low_noise,
        'jump_scares', jump_scares, 'flashing_lights', flashing_lights, 'loud_audio', loud_audio, 'scare_level', scare_level, 'stroller_friendly', stroller_friendly)";

    public function testEveryAppWriteKeepsTheLegacyColumnsAndDetailsEqual(): void
    {
        $this->configureCommunity();
        $a = $this->listHome('11', 'alice@example.test', self::listing(['nonfood' => true, 'jump_scares' => true, 'scare_level' => 'intense']));
        $this->listHome('12', 'bob@example.test', ['start' => '18:00', 'end' => '20:00', 'details' => ['adult_drinks' => true, 'stroller_friendly' => true, 'flashing_lights' => true]]);
        $this->signIn('alice@example.test');
        $r = $this->json('PATCH', "/api/me/homes/$a", ['details' => ['candy' => false, 'nonfood' => true, 'loud_audio' => true], 'status' => 'out_of_candy'], ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['candy' => false, 'nonfood' => true, 'loud_audio' => true, 'scare_level' => 'intense'],
            array_intersect_key($r['home']['listing']['details'], ['candy' => 1, 'nonfood' => 1, 'loud_audio' => 1, 'scare_level' => 1]), 'a partial update keeps the other details');
        $r = $this->json('PATCH', "/api/me/homes/$a", ['published' => false], ['X-CSRF-Token' => $this->csrf()]);
        self::assertTrue($r['home']['listing']['details']['nonfood']);

        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM listings'));
        self::assertSame([], $this->rowsWhereDetailsDiffer());
        self::assertSame(['candy' => false, 'nonfood' => true, 'loud_audio' => true], array_intersect_key($this->columns($a), ['candy' => 1, 'nonfood' => 1, 'loud_audio' => 1]), 'the legacy columns got the same values');
    }

    public function testRowsWrittenThe09WayGetTheirDetailsFromTheColumns(): void
    {
        $this->configureCommunity();
        $id = $this->listHome('21', 'carol@example.test', self::listing());
        // 0.9 writes only the columns: an UPDATE like its owner PATCH, then a row it inserts after a release.
        $this->db()->executeStatement('UPDATE listings SET candy = FALSE, nonfood = TRUE, scare_level = \'mild\' WHERE address_id = ?', [$id]);
        $other = $this->addAddress('22', 'Pumpkin Ln', 40.006, -75.004, 'west');
        $this->db()->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) SELECT ?, user_id, NOW(), NOW() FROM claims WHERE address_id = ?', [$other, $id]);
        $this->insertThe09Way($other);

        self::assertSame([], $this->rowsWhereDetailsDiffer());
        $homes = array_column($this->json('GET', '/api/homes')['homes'], null, 'id');
        self::assertSame(['candy' => false, 'nonfood' => true, 'scare_level' => 'mild'], array_intersect_key($homes[$id], ['candy' => 1, 'nonfood' => 1, 'scare_level' => 1]));
        self::assertTrue($homes[$other]['adult_drinks']);
    }

    public function testThePublicMapReadsDetails(): void
    {
        $this->configureCommunity();
        $id = $this->listHome('31', 'dave@example.test', self::listing());
        $this->db()->executeStatement('ALTER TABLE listings DISABLE TRIGGER listings_details_sync');
        try {
            $this->db()->executeStatement("UPDATE listings SET details = details || '{\"stroller_friendly\": true, \"scare_level\": \"intense\"}'::jsonb WHERE address_id = ?", [$id]);
        } finally {
            $this->db()->executeStatement('ALTER TABLE listings ENABLE TRIGGER listings_details_sync');
        }
        self::assertFalse($this->columns($id)['stroller_friendly'], 'only details changed');
        $home = $this->json('GET', '/api/homes/'.$id)['home'];
        self::assertTrue($home['stroller_friendly']);
        self::assertSame('intense', $home['scare_level']);
        self::assertSame(['candy' => true, 'low_noise' => true], array_intersect_key($home, ['candy' => 1, 'low_noise' => 1]), 'the rest of details as listed');
    }

    public function testTheMigrationGoesDownAndUpAgainAndBackfillsEveryRow(): void
    {
        $this->configureCommunity();
        $first = $this->listHome('41', 'erin@example.test', self::listing(['nonfood' => true]));
        try {
            $this->migrate(self::PREVIOUS);
            self::assertFalse($this->hasDetailsColumn(), 'down drops the column');
            self::assertFalse($this->db()->fetchOne("SELECT 1 FROM pg_proc WHERE proname = 'listings_details_from_columns'"), 'and the trigger function');
            self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM listings'), 'listings themselves are untouched');
            // 0.9 runs on the old schema and writes another listing.
            $second = $this->addAddress('42', 'Pumpkin Ln', 40.007, -75.004, 'west');
            $this->db()->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) SELECT ?, user_id, NOW(), NOW() FROM claims WHERE address_id = ?', [$second, $first]);
            $this->insertThe09Way($second);
        } finally {
            $this->migrate('latest');
        }
        self::assertTrue($this->hasDetailsColumn());
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM listings WHERE details <> '{}'::jsonb"));
        self::assertSame([], $this->rowsWhereDetailsDiffer(), 'up backfills details from the columns for every row');
        self::assertTrue($this->json('GET', '/api/homes/'.$first)['home']['nonfood']);
    }

    /** @return list<int> listings whose details differ from their legacy columns */
    private function rowsWhereDetailsDiffer(): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT address_id FROM listings WHERE details IS DISTINCT FROM '.self::FROM_COLUMNS));
    }

    /** @return array<string,mixed> */
    private function columns(int $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights, loud_audio, scare_level, stroller_friendly FROM listings WHERE address_id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /** A listing as 0.9.0-beta.1 inserts it: the Halloween columns only. */
    private function insertThe09Way(int $addressId): void
    {
        $this->db()->executeStatement(
            "INSERT INTO listings (address_id, event_date, start_local, end_local, candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights,
                loud_audio, scare_level, stroller_friendly, note, status, published, published_at, updated_at, moderation_hidden)
             VALUES (?, '2026-10-31', '17:00', '20:00', TRUE, FALSE, TRUE, FALSE, FALSE, FALSE, FALSE, 'gentle', FALSE, 'From 0.9', 'active', TRUE, NOW(), NOW(), FALSE)",
            [$addressId],
        );
    }

    private function hasDetailsColumn(): bool
    {
        return false !== $this->db()->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'listings' AND column_name = 'details'");
    }

    /** Runs the migrations command in a kernel of its own (Doctrine Migrations freezes its plan after one run). */
    private function migrate(string $version): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $app = new Application($kernel);
            $app->setAutoExit(false);
            $out = new BufferedOutput();
            $code = $app->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', 'version' => $version, '--no-interaction' => true, '--allow-no-migration' => true]), $out);
            self::assertSame(0, $code, $out->fetch());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * Lists a home through the email-link claim (ClaimService → ListingRepository::insert).
     *
     * @param array<string,mixed> $listing
     */
    private function listHome(string $house, string $email, array $listing): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, -75.005, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => $listing]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }
}
