<?php

declare(strict_types=1);

namespace App\Claim;

use App\Address\AddressEligibility;
use App\Address\AddressRegistry;
use App\Audit\AuditLog;
use App\Community\CommunityConfig;
use App\Listing\ListingDraft;
use App\Listing\ListingRepository;
use App\Security\EmailNormalizer;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @module Claim/ClaimService
 * @description "First verified email wins." Prepares claim intents (no address reservation) and
 *     completes claims atomically: the claims primary key is the single source of ownership truth,
 *     taken with INSERT … ON CONFLICT DO NOTHING inside one transaction, and the listing is
 *     published in that same transaction. A losing racer gets already_claimed; nothing is overwritten.
 *
 * @dependencies Address/*, Listing/*, Audit/AuditLog, DBAL
 */
final class ClaimService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly AddressEligibility $eligibility,
        private readonly AddressRegistry $registry,
        private readonly ListingRepository $listings,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Validates target + listing and stores a short-lived intent. Does NOT reserve the address.
     *
     * @param array<string,mixed> $addressInput
     * @param array<string,mixed> $listingInput
     *
     * @return array{intent_id:string, address:string, source:string, address_key:string}
     */
    public function prepareIntent(string $email, array $addressInput, array $listingInput, CommunityConfig $community, int $minutes): array
    {
        $this->assertOpen($community);
        $target = $this->eligibility->resolve($addressInput, $community);
        if (null !== $target['address_id'] && $this->registry->isClaimed($target['address_id'])) {
            throw new ClaimRejectedException('already_claimed', 'This home is already claimed.', $target['address_id']);
        }
        $draft = ListingDraft::fromInput($listingInput, $community);
        $id = Uuid::v4()->toRfc4122();
        $now = $this->clock->now();
        $this->db->insert('claim_intents', [
            'id' => $id,
            'address_id' => $target['address_id'],
            'candidate' => null !== $target['candidate'] ? json_encode($target['candidate'], \JSON_THROW_ON_ERROR) : null,
            'email_normalized' => $email,
            'listing_draft' => json_encode($draft, \JSON_THROW_ON_ERROR),
            'expires_at' => $now->modify(sprintf('+%d minutes', $minutes))->format('Y-m-d H:i:s.uP'),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);

        return ['intent_id' => $id, 'address' => $target['display'],
            'source' => null !== $target['address_id'] ? 'registry' : 'self_entry',
            'address_key' => null !== $target['address_id'] ? (string) $target['address_id'] : 'self:'.$target['candidate']['normalized']];
    }

    /**
     * Summary for the GET confirmation page: the address AND the exact listing that confirming will
     * publish, so nobody publishes details someone else typed without seeing them.
     *
     * @return array{address:string, listing:array<string,mixed>}|null
     */
    public function intentSummary(string $intentId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT address_id, candidate, listing_draft FROM claim_intents WHERE id = ?', [$intentId]);
        if (false === $row) {
            return null;
        }
        $listing = json_decode((string) $row['listing_draft'], true, 16) ?: [];
        if (null !== $row['address_id']) {
            $a = $this->registry->find((int) $row['address_id']);

            return null === $a ? null : ['address' => (string) $a['display_address'], 'listing' => $listing];
        }
        $c = json_decode((string) $row['candidate'], true);

        return ['address' => (string) ($c['display'] ?? ''), 'listing' => $listing];
    }

    /**
     * Consumes an intent for a verified user and claims atomically. Called inside the sign-in POST.
     */
    public function completeIntent(string $intentId, int $userId, string $email, CommunityConfig $community): ClaimOutcome
    {
        return $this->db->transactional(function (Connection $db) use ($intentId, $userId, $email, $community): ClaimOutcome {
            $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
            $intent = $db->fetchAssociative(
                'UPDATE claim_intents SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL AND expires_at > :now AND email_normalized = :e
                 RETURNING address_id, candidate, listing_draft',
                ['now' => $now, 'id' => $intentId, 'e' => $email],
            );
            if (false === $intent) {
                return new ClaimOutcome('rejected', null, null, 'This listing request expired. Please start again.');
            }
            $draft = json_decode((string) $intent['listing_draft'], true, 16, \JSON_THROW_ON_ERROR);
            $input = null !== $intent['address_id']
                ? ['address_id' => (int) $intent['address_id']]
                : json_decode((string) $intent['candidate'], true, 16, \JSON_THROW_ON_ERROR);

            return $this->claimInTransaction($db, $input, $userId, $draft, $community, 'email-link', true);
        });
    }

    /**
     * Direct claim for an already signed-in (email-verified) user.
     *
     * @param array<string,mixed> $addressInput
     * @param array<string,mixed> $listingInput
     */
    public function claimDirect(array $addressInput, array $listingInput, int $userId, CommunityConfig $community): ClaimOutcome
    {
        $draft = ListingDraft::fromInput($listingInput, $community);

        return $this->db->transactional(fn (Connection $db) => $this->claimInTransaction($db, $addressInput, $userId, $draft, $community, 'dashboard', false));
    }

    /**
     * @param array<string,mixed> $input    the visitor's address input, or the stored intent's (already matched) target
     * @param array<string,mixed> $draft
     * @param bool                $fromIntent true when completing a claim intent whose registry id was matched when it was prepared
     */
    private function claimInTransaction(Connection $db, array $input, int $userId, array $draft, CommunityConfig $community, string $source, bool $fromIntent): ClaimOutcome
    {
        try {
            $this->assertOpen($community);
            // Re-validate eligibility at completion time: boundary/zone config may have changed since the intent.
            $target = !$fromIntent ? $this->eligibility->resolve($input, $community) : (isset($input['address_id'])
                ? $this->eligibility->registryAddress((int) $input['address_id'], $community)
                : $this->eligibility->selfEntry([
                    'house_number' => $input['house_number'] ?? '', 'street' => $input['street'] ?? '', 'unit' => $input['unit'] ?? null,
                    'lat' => $input['lat'] ?? null, 'lon' => $input['lon'] ?? null, 'zone_key' => $input['zone_key'] ?? null,
                ], $community));
        } catch (ClaimRejectedException $e) {
            if ('already_claimed' !== $e->reason) {
                return new ClaimOutcome('closed' === $e->reason ? 'closed' : 'rejected', $e->addressId, null, $e->getMessage());
            }
            throw $e;
        }

        if (null !== $target['address_id'] && false !== $db->fetchOne('SELECT 1 FROM claims WHERE address_id = ? AND user_id = ?', [$target['address_id'], $userId])) {
            return new ClaimOutcome('already_yours', $target['address_id'], $target['display'], 'You already manage this home.');
        }
        // One resident manages a bounded number of homes. The per-user lock makes the count exact even
        // when several claims for the same user race (dashboard + email link at once).
        $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))', ['k' => 'claims-user:'.$userId]);
        $limit = (int) ($community->settings['registration']['max_homes_per_user'] ?? 2);
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM claims WHERE user_id = ?', [$userId]) >= $limit) {
            return new ClaimOutcome('limit', $target['address_id'], $target['display'],
                sprintf('Each neighbor can manage up to %d homes. Ask the organizer if you need to manage more.', $limit));
        }

        // A new (self-entered) address can be held until an organizer approves it (registration.review_new_addresses).
        $review = null === $target['address_id'] && (bool) ($community->settings['registration']['review_new_addresses'] ?? false);
        $addressId = $target['address_id'] ?? $this->registry->insert(
            $target['candidate'], $target['zone_key'], $target['lat'], $target['lon'], 'self_entry', true, false,
        );
        if ($review) {
            $db->executeStatement('UPDATE eligible_addresses SET moderation_hold = TRUE WHERE id = ? AND reviewed_at IS NULL', [$addressId]);
        }
        $claimed = $db->fetchOne(
            'INSERT INTO claims (address_id, user_id, claimed_at, updated_at) VALUES (:a, :u, :now, :now)
             ON CONFLICT (address_id) DO NOTHING RETURNING address_id',
            ['a' => $addressId, 'u' => $userId, 'now' => $this->clock->now()->format('Y-m-d H:i:s.uP')],
        );
        if (false === $claimed) {
            $owner = (int) $db->fetchOne('SELECT user_id FROM claims WHERE address_id = ?', [$addressId]);
            if ($owner === $userId) {
                return new ClaimOutcome('already_yours', $addressId, $target['display'], 'You already manage this home.');
            }
            $this->audit->record($userId, 'user#'.$userId, 'claim.conflict', 'address', (string) $addressId, ['source' => $source]);

            return new ClaimOutcome('already_claimed', $addressId, $target['display'], 'This home is already claimed.');
        }
        if (null === $target['address_id']) {
            // The claim is ours now: an existing unreviewed self-entry (released earlier) takes this resident's pin.
            $this->registry->repinSelfEntry($addressId, $target['zone_key'], $target['lat'], $target['lon']);
        }
        $this->listings->insert($db, $addressId, $community->eventDate(), $draft);
        if ($review && false !== $db->fetchOne('SELECT 1 FROM listings WHERE address_id = ? AND moderation_hidden', [$addressId])) {
            $db->executeStatement('UPDATE listings SET moderation_reason = ? WHERE address_id = ?', [ListingRepository::PENDING_REVIEW, $addressId]);
            $this->audit->record($userId, 'user#'.$userId, 'claim.pending_review', 'address', (string) $addressId, ['source' => $source]);

            return new ClaimOutcome('pending_review', $addressId, $target['display'], 'Thanks! An organizer will check this new address before it appears on the map.');
        }
        $this->audit->record($userId, 'user#'.$userId, 'claim.published', 'address', (string) $addressId, ['source' => $source]);

        return new ClaimOutcome('published', $addressId, $target['display'], 'Your home is on the map!');
    }

    private function assertOpen(CommunityConfig $community): void
    {
        $state = $community->registrationState();
        if (!$state['open']) {
            throw new ClaimRejectedException('closed', (string) $state['reason']);
        }
    }

    public static function maskedEmail(string $email): string
    {
        return EmailNormalizer::mask($email);
    }

    public function purgeExpiredIntents(): int
    {
        return $this->db->executeStatement('DELETE FROM claim_intents WHERE expires_at < ?', [$this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.uP')]);
    }
}
