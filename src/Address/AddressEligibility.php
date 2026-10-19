<?php

declare(strict_types=1);

namespace App\Address;

use App\Claim\ClaimRejectedException;
use App\Community\CommunityConfig;

/**
 * @module Address/AddressEligibility
 * @description Authoritative server-side eligibility for a claim target: either a reviewed registry
 *     address, or a tightly scoped self-entry (allow-listed street + resident-confirmed pin inside the
 *     approved boundary and a claimable zone). Never invents coordinates; browser checks are hints only.
 *
 * @input address id or candidate {house_number, street, unit?, lat, lon, zone_key?}; CommunityConfig
 * @output array{address_id:?int, candidate:?array, display:string, zone_key:?string, lat:float, lon:float}
 * @dependencies Address/AddressRegistry, Address/AddressNormalizer, Community/CommunityConfig
 */
final class AddressEligibility
{
    public const PIN_COLLISION_METERS = 4.0;

    public function __construct(
        private readonly AddressRegistry $registry,
        private readonly AddressNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<string,mixed> $input either {address_id} or {house_number, street, unit?, lat, lon, zone_key?}
     *
     * @return array{address_id:?int, candidate:?array<string,mixed>, display:string, zone_key:?string, lat:float, lon:float}
     */
    public function resolve(array $input, CommunityConfig $community): array
    {
        if (isset($input['address_id'])) {
            return $this->registryAddress((int) $input['address_id'], $community);
        }

        return $this->selfEntry($input, $community);
    }

    /** @return array{address_id:int, candidate:null, display:string, zone_key:?string, lat:float, lon:float} */
    public function registryAddress(int $id, CommunityConfig $community): array
    {
        $row = $id > 0 ? $this->registry->find($id) : null;
        if (null === $row || !$row['enabled']) {
            throw new ClaimRejectedException('not_eligible', 'That address is not on this neighborhood’s list.');
        }
        $lat = (float) $row['latitude'];
        $lon = (float) $row['longitude'];
        if (!$community->insideBoundary($lat, $lon)) {
            throw new ClaimRejectedException('outside_boundary', 'That address is outside the approved neighborhood boundary.');
        }
        $zoneKey = null !== $row['zone_key'] ? (string) $row['zone_key'] : null;
        $this->assertZoneClaimable($zoneKey, $community);

        return ['address_id' => (int) $row['id'], 'candidate' => null, 'display' => (string) $row['display_address'], 'zone_key' => $zoneKey, 'lat' => $lat, 'lon' => $lon];
    }

    /**
     * @param array<string,mixed> $in
     *
     * @return array{address_id:?int, candidate:?array<string,mixed>, display:string, zone_key:?string, lat:float, lon:float}
     */
    public function selfEntry(array $in, CommunityConfig $community): array
    {
        if ('registry' === $community->addressMode()) {
            throw new ClaimRejectedException('self_entry_disabled', 'Choose your address from the list. If it is missing, contact the organizer.');
        }
        try {
            $parts = $this->normalizer->fromParts((string) ($in['house_number'] ?? ''), (string) ($in['street'] ?? ''), isset($in['unit']) ? (string) $in['unit'] : null);
        } catch (InvalidAddressException $e) {
            throw new ClaimRejectedException('invalid_address', $e->getMessage());
        }
        if (!\in_array($parts['street'], $community->streetAllowlist(), true)) {
            throw new ClaimRejectedException('street_not_allowed', 'That street is not part of this neighborhood’s street list.');
        }
        $existing = $this->registry->findByNormalized($parts['normalized']);
        if (null !== $existing) {
            return $this->registryAddress((int) $existing['id'], $community);
        }
        $lat = $in['lat'] ?? null;
        $lon = $in['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            throw new ClaimRejectedException('invalid_address', 'Place the pin on your house on the map.');
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        if (!$community->insideBoundary($lat, $lon)) {
            // A pin on a named non-residential or not-yet-open area (e.g. the clubhouse) says so.
            foreach ($community->zonesAt($lat, $lon) as $zoneKey) {
                $this->assertZoneClaimable($zoneKey, $community);
            }
            throw new ClaimRejectedException('outside_boundary', 'The pin is outside the approved neighborhood boundary.');
        }
        $requested = isset($in['zone_key']) && '' !== $in['zone_key'] ? (string) $in['zone_key'] : null;
        $zoneKey = $this->zoneForPoint($lat, $lon, $requested, $community);
        $this->assertZoneClaimable($zoneKey, $community);
        $near = $this->registry->nearbyOther($lat, $lon, self::PIN_COLLISION_METERS, $parts['normalized']);
        if (null !== $near) {
            throw new ClaimRejectedException('pin_collision', 'That pin is on top of another listed address. Move it onto your own house.');
        }

        return ['address_id' => null, 'candidate' => $parts + ['zone_key' => $zoneKey, 'lat' => $lat, 'lon' => $lon],
            'display' => $parts['display'], 'zone_key' => $zoneKey, 'lat' => $lat, 'lon' => $lon];
    }

    private function zoneForPoint(float $lat, float $lon, ?string $requested, CommunityConfig $community): ?string
    {
        if (null !== $requested && null === $community->zone($requested)) {
            throw new ClaimRejectedException('unknown_zone', 'That zone does not exist in this neighborhood.');
        }
        $hits = $community->zonesAt($lat, $lon);
        if (\count($hits) > 1) {
            throw new ClaimRejectedException('ambiguous_zone', 'That location sits where two zones overlap. Please contact the organizer to add your address.');
        }
        if (1 === \count($hits)) {
            if (null !== $requested && $requested !== $hits[0]) {
                throw new ClaimRejectedException('zone_mismatch', sprintf('That pin is in %s, not the zone you selected.', $community->zone($hits[0])['label'] ?? $hits[0]));
            }

            return $hits[0];
        }
        // No zone polygon contains the point: only zones without geometry may be chosen manually.
        if (null !== $requested && isset($community->zoneShapes[$requested])) {
            throw new ClaimRejectedException('zone_mismatch', 'That pin is not inside the zone you selected.');
        }

        return $requested;
    }

    private function assertZoneClaimable(?string $zoneKey, CommunityConfig $community): void
    {
        if (null === $zoneKey) {
            return;
        }
        $zone = $community->zone($zoneKey);
        if (null === $zone) {
            throw new ClaimRejectedException('unknown_zone', 'That address is assigned to a zone that no longer exists. Contact the organizer.');
        }
        if (!$zone['claimable']) {
            throw new ClaimRejectedException('zone_not_claimable', sprintf('%s is not a residential area, so homes there cannot be listed.', $zone['label']));
        }
        if ('active' !== $zone['status']) {
            throw new ClaimRejectedException('zone_not_open', sprintf('%s is not open for listings yet. Contact the organizer if your home is ready.', $zone['label']));
        }
    }
}
