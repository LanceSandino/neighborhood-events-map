<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: security audit run-1 lead 5. A self-entered address that nobody reviewed stays in the
 * registry after its claimant releases it (or an organizer unclaims it). The next resident who typed the
 * same address inherited that earlier, unverified pin, so anyone could pre-place a neighbor's home at the
 * wrong spot. Now an unreviewed, unclaimed self-entry takes the new resident's own pin; reviewed or
 * imported addresses keep theirs.
 */
final class SelfEntryRepinTest extends AppTestCase
{
    private function selfEntryClaim(string $email, float $lat, float $lon): void
    {
        $this->confirm($this->requestLink($email, ['address' => ['house_number' => '40', 'street' => 'Lantern Way', 'lat' => $lat, 'lon' => $lon],
            'listing' => self::listing()]));
        self::assertSame(303, $this->code());
    }

    private function release(int $id): void
    {
        $this->json('DELETE', '/api/me/homes/'.$id, ['confirm' => 'RELEASE'], ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->clear();
    }

    /** @return array{0:float,1:float} */
    private function pin(int $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT latitude, longitude FROM eligible_addresses WHERE id = ?', [$id]);

        return [round((float) $row['latitude'], 6), round((float) $row['longitude'], 6)];
    }

    public function testNextResidentPlacesTheirOwnPinOnAReleasedUnreviewedAddress(): void
    {
        $this->configureCommunity();
        $this->selfEntryClaim('first@example.test', 40.0061, -74.9961); // a pin somewhere else in the east zone
        $id = (int) $this->db()->fetchOne("SELECT id FROM eligible_addresses WHERE normalized_address = '40 LANTERN WAY'");
        $this->release($id);

        $this->selfEntryClaim('resident@example.test', 40.0102, -74.9932);
        self::assertSame([40.0102, -74.9932], $this->pin($id), 'the new resident\'s own pin, not the earlier one');
        $home = $this->json('GET', '/api/homes/'.$id)['home'];
        self::assertSame([40.0102, -74.9932], [$home['lat'], $home['lon']]);
    }

    public function testWithReviewOnTheReleasedAddressWaitsForApprovalAgain(): void
    {
        $this->configureCommunity(['registration' => ['review_new_addresses' => true]]);
        $this->selfEntryClaim('first@example.test', 40.0061, -74.9961);
        $id = (int) $this->db()->fetchOne("SELECT id FROM eligible_addresses WHERE normalized_address = '40 LANTERN WAY'");
        $this->release($id);

        $this->selfEntryClaim('resident@example.test', 40.0102, -74.9932);
        self::assertSame('pending_review', $this->json('GET', '/api/me')['claim_outcome']['status']);
        self::assertSame([40.0102, -74.9932], $this->pin($id));
    }

    public function testReviewedAddressKeepsItsApprovedPin(): void
    {
        $this->configureCommunity();
        $this->selfEntryClaim('first@example.test', 40.0061, -74.9961);
        $id = (int) $this->db()->fetchOne("SELECT id FROM eligible_addresses WHERE normalized_address = '40 LANTERN WAY'");
        $this->db()->executeStatement('UPDATE eligible_addresses SET reviewed_at = now() WHERE id = ?', [$id]); // organizer approved it
        $this->release($id);

        $this->selfEntryClaim('resident@example.test', 40.0102, -74.9932);
        self::assertSame([40.0061, -74.9961], $this->pin($id), 'an approved pin is the registry\'s, not the visitor\'s');
    }
}
