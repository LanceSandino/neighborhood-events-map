<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Address\AddressCsvImporter;
use App\Address\AddressNormalizer;
use App\Address\AddressRegistry;
use App\Address\InvalidAddressException;
use App\Admin\ClaimAdministration;
use App\Audit\AuditLog;
use App\Community\BrandingStore;
use App\Community\CommunityRepository;
use App\Dispute\DisputeService;
use App\Event\EventType;
use App\Event\EventTypeRegistry;
use App\Geo\ZoneResolver;
use App\Listing\ListingRepository;
use App\Security\User;
use App\Shared\ApiError;
use App\Shared\JsonBody;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * @module Controller/Api/AdminApiController
 * @description Organizer API (ROLE_ADMIN via access_control; CSRF + idle timeout enforced first):
 *     community settings/branding, GeoJSON boundary & zones (import/export/approve), registration
 *     switch, address inventory, claim transfer/unclaim, listing moderation, disputes, audit, export. The
 *     settings come with the event types an organizer may choose and the current type's console labels. Listing
 *     counts and states are the active event's; events themselves are managed by EventsAdminApiController.
 *
 * @dependencies Community/*, Admin/ClaimAdministration, Address/*, Dispute/DisputeService, Event/EventTypeRegistry, Audit/AuditLog, DBAL
 */
#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')] // also enforced by access_control; declared here so a route rule is never the only lock
final class AdminApiController
{
    public function __construct(
        private readonly CommunityRepository $community,
        private readonly AuditLog $audit,
        private readonly Connection $db,
        private readonly EventTypeRegistry $types,
        private readonly ListingRepository $listings,
    ) {
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $c = $this->community->current();
        $count = fn (string $sql) => (int) $this->db->fetchOne($sql);
        $listingCounts = $this->listings->counts($c->event?->id); // the active event's listings
        $warnings = [];
        foreach ((new ZoneResolver($c->zoneShapes))->overlaps() as $o) {
            $warnings[] = sprintf('Zones "%s" and "%s" overlap by about %s m².', $o['a'], $o['b'], number_format($o['approx_m2']));
        }
        $missingZones = array_values(array_filter(array_column($c->zones(), 'key'), fn ($k) => !isset($c->zoneShapes[$k])));

        return new JsonResponse([
            'configured' => $c->configured,
            'name' => $c->name(),
            'registration' => $c->registrationState() + ['enabled' => $c->settings['registration']['enabled']],
            'boundary' => ['present' => null !== $c->boundary, 'approved' => $c->boundaryApproved, 'version' => $c->boundaryVersion,
                'zones_with_geometry' => array_keys($c->zoneShapes), 'zones_without_geometry' => $missingZones, 'warnings' => $warnings],
            'counts' => [
                'addresses' => $count('SELECT COUNT(*) FROM eligible_addresses'),
                'addresses_outside_boundary' => $count('SELECT COUNT(*) FROM eligible_addresses WHERE NOT inside_boundary'),
                'claims' => $count('SELECT COUNT(*) FROM claims'),
                'public_listings' => $listingCounts['public'],
                'hidden_listings' => $listingCounts['hidden'],
                'pending_review' => $listingCounts['pending_review'],
                'open_disputes' => $count("SELECT COUNT(*) FROM disputes WHERE status = 'open'"),
            ],
            'versions' => $this->community->geometryVersions(),
            'pending_addresses' => $this->listings->pendingAddresses($c->event?->id),
            'event' => null === $c->event ? null : ['id' => $c->event->id, 'name' => $c->event->name, 'type' => $c->event->type, 'starts_on' => $c->event->startsOn, 'ends_on' => $c->event->endsOn],
        ]);
    }

    #[Route('/community', methods: ['GET'])]
    public function getCommunity(): JsonResponse
    {
        $c = $this->community->current();

        return new JsonResponse(['settings' => $c->settings, 'event_types' => array_map(fn (EventType $t) => $this->typeView($t), $this->types->enabled()),
            'event_type' => $this->typeView($this->types->for($c))]);
    }

    #[Route('/community', methods: ['PUT'])]
    public function putCommunity(#[CurrentUser] User $admin, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $settings = \is_array($body['settings'] ?? null) ? $body['settings'] : throw new ValidationException(['settings' => 'Missing settings object.']);
        $settings['branding']['logo'] = $this->community->current()->settings['branding']['logo'];
        $saved = $this->community->saveSettings($settings, $admin->id);
        $this->audit->record($admin->id, 'admin#'.$admin->id, 'community.updated', 'community', $saved['community']['id'], [], 'admin');

        return new JsonResponse(['settings' => $saved]);
    }

    #[Route('/community/logo', methods: ['POST'])]
    public function logo(#[CurrentUser] User $admin, Request $request, BrandingStore $store): JsonResponse
    {
        $file = $request->files->get('logo');
        $settings = $this->community->current()->settings;
        if ('1' === $request->request->get('remove')) {
            $settings['branding']['logo'] = null;
        } else {
            if (null === $file || !$file->isValid()) {
                throw new ValidationException(['logo' => 'Choose an SVG, PNG, JPEG or WebP file up to 512 KB.']);
            }
            $settings['branding']['logo'] = $store->storeLogo($file->getPathname(), $file->getClientOriginalName());
        }
        $saved = $this->community->saveSettings($settings, $admin->id);
        $this->audit->record($admin->id, 'admin#'.$admin->id, 'community.logo', 'community', null, ['logo' => $saved['branding']['logo']], 'admin');

        return new JsonResponse(['logo' => $saved['branding']['logo']]);
    }

    #[Route('/registration', methods: ['POST'])]
    public function registration(#[CurrentUser] User $admin, Request $request): JsonResponse
    {
        $enabled = (bool) (JsonBody::decode($request)['enabled'] ?? false);
        $settings = $this->community->current()->settings;
        $settings['registration']['enabled'] = $enabled;
        $this->community->saveSettings($settings, $admin->id);
        $this->audit->record($admin->id, 'admin#'.$admin->id, $enabled ? 'registration.opened' : 'registration.closed', 'community', null, [], 'admin');

        return new JsonResponse(['registration' => $this->community->current()->registrationState()]);
    }

    #[Route('/geometries', methods: ['GET'])]
    public function geometries(): JsonResponse
    {
        $c = $this->community->current();

        return new JsonResponse(['geojson' => $this->community->exportGeometry(), 'approved' => $c->boundaryApproved, 'version' => $c->boundaryVersion,
            'zones' => $c->zones(), 'center' => $c->mapCenter(), 'tiles' => $c->settings['map']['tiles']]);
    }

    #[Route('/geometries', methods: ['PUT'], name: 'admin_geometries_put')]
    #[Route('/geometries/import', methods: ['POST'], name: 'admin_geometries_import')]
    public function saveGeometries(#[CurrentUser] User $admin, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (null !== $file) {
            $document = (string) file_get_contents($file->getPathname());
            $confirm = '1' === $request->request->get('confirm_outside_claims');
        } else {
            $body = JsonBody::decode($request, 3_000_000);
            $document = \is_array($body['geojson'] ?? null) ? $body['geojson'] : throw new ValidationException(['geojson' => 'Missing GeoJSON.']);
            $confirm = true === ($body['confirm_outside_claims'] ?? false);
        }
        $result = $this->community->replaceGeometry($document, $admin->id, false, $confirm);
        $this->audit->record($admin->id, 'admin#'.$admin->id, 'geometry.saved', 'community', (string) $result['version'],
            ['zones' => $result['zones'], 'outside_claims' => $result['outside_claims']], 'admin');

        return new JsonResponse(['result' => $result, 'approved' => false]);
    }

    #[Route('/geometries/export', methods: ['GET'])]
    public function export(): Response
    {
        $c = $this->community->current();

        return new Response(json_encode($this->community->exportGeometry(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)."\n", 200, [
            'Content-Type' => 'application/geo+json',
            'Content-Disposition' => sprintf('attachment; filename="%s-boundary-v%d.geojson"', $c->settings['community']['id'], $c->boundaryVersion ?? 0),
        ]);
    }

    #[Route('/geometries/approve', methods: ['POST'])]
    public function approve(#[CurrentUser] User $admin, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        if (true !== ($body['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm that you reviewed the boundary against real streets and eligibility.');
        }
        $approve = 'revoke' !== ($body['action'] ?? 'approve');
        if ($approve) {
            if (!$this->community->approveBoundary($admin->id)) {
                return ApiError::response(422, 'no_boundary', 'Draw or import a boundary first.');
            }
        } else {
            $this->community->revokeBoundaryApproval();
        }
        $this->audit->record($admin->id, 'admin#'.$admin->id, $approve ? 'boundary.approved' : 'boundary.unapproved', 'community', (string) $this->community->current()->boundaryVersion, [], 'admin');

        return new JsonResponse(['approved' => $this->community->current()->boundaryApproved, 'registration' => $this->community->current()->registrationState()]);
    }

    #[Route('/addresses', methods: ['GET'])]
    public function addresses(Request $request, AddressRegistry $registry): JsonResponse
    {
        $rows = $registry->search((string) $request->query->get('q', ''), 200, max(0, (int) $request->query->get('offset', 0)), $this->community->current()->event?->id);

        return new JsonResponse(['total' => $registry->count(), 'addresses' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'address' => $r['display_address'], 'zone' => $r['zone_key'], 'lat' => (float) $r['latitude'], 'lon' => (float) $r['longitude'],
            'enabled' => (bool) $r['enabled'], 'inside_boundary' => (bool) $r['inside_boundary'], 'source' => $r['source'],
            'owner_user_id' => null !== $r['user_id'] ? (int) $r['user_id'] : null, 'owner_email' => $r['owner_email'], 'claimed_at' => $r['claimed_at'],
            'listing' => null === $r['status'] ? null : ['status' => $r['status'], 'published' => (bool) $r['published'], 'hidden' => (bool) $r['moderation_hidden'],
                'pending_review' => (bool) $r['moderation_hidden'] && ListingRepository::PENDING_REVIEW === $r['moderation_reason'], 'event_date' => $r['event_date']],
        ], $rows)]);
    }

    #[Route('/addresses', methods: ['POST'])]
    public function addAddress(#[CurrentUser] User $admin, Request $request, AddressRegistry $registry, AddressNormalizer $normalizer): JsonResponse
    {
        $b = JsonBody::decode($request);
        try {
            $parts = isset($b['address']) ? $normalizer->parse((string) $b['address']) : $normalizer->fromParts((string) ($b['house_number'] ?? ''), (string) ($b['street'] ?? ''), $b['unit'] ?? null);
        } catch (InvalidAddressException $e) {
            throw new ValidationException(['address' => $e->getMessage()]);
        }
        [$lat, $lon, $zone, $inside] = $this->validatedPoint($b);
        if (null !== $registry->findByNormalized($parts['normalized'])) {
            throw new ValidationException(['address' => 'That address already exists.']);
        }
        $id = $registry->insert($parts, $zone, $lat, $lon, 'admin', $inside, true);
        $this->audit->record($admin->id, 'admin#'.$admin->id, 'address.added', 'address', (string) $id, [], 'admin');

        return new JsonResponse(['id' => $id], 201);
    }

    #[Route('/addresses/{id}', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function updateAddress(#[CurrentUser] User $admin, int $id, Request $request, AddressRegistry $registry, AddressNormalizer $normalizer): JsonResponse
    {
        $row = $registry->find($id) ?? throw new ValidationException(['id' => 'Unknown address.']);
        $b = JsonBody::decode($request) + ['lat' => $row['latitude'], 'lon' => $row['longitude'], 'zone_key' => $row['zone_key']];
        [$lat, $lon, $zone, $inside] = $this->validatedPoint($b);
        $changes = ['latitude' => $lat, 'longitude' => $lon, 'zone_key' => $zone];
        if (\array_key_exists('enabled', $b)) {
            $changes['enabled'] = (bool) $b['enabled'];
        }
        if (isset($b['display_address']) && '' !== trim((string) $b['display_address'])) {
            // Only another spelling of the same address (capitals, spacing, abbreviations): plain characters,
            // the house number first (visitors send it back with a claim by id), never a different address.
            $display = preg_replace('~\s+~', ' ', trim((string) $b['display_address'])) ?? '';
            try {
                $same = $normalizer->parse($display)['normalized'] === $row['normalized_address'];
            } catch (InvalidAddressException $e) {
                throw new ValidationException(['display_address' => $e->getMessage()]);
            }
            if (!$same || mb_strlen($display) > 160) {
                throw new ValidationException(['display_address' => 'Change only how this address is written (capitals, spacing); add a new address for a different one.']);
            }
            $changes['display_address'] = $display;
        }
        $registry->update($id, $changes, $inside);
        $this->audit->record($admin->id, 'admin#'.$admin->id, 'address.updated', 'address', (string) $id,
            ['before' => ['lat' => (float) $row['latitude'], 'lon' => (float) $row['longitude'], 'zone' => $row['zone_key']], 'after' => ['lat' => $lat, 'lon' => $lon, 'zone' => $zone]], 'admin');

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/addresses/import', methods: ['POST'])]
    public function importAddresses(#[CurrentUser] User $admin, Request $request, AddressCsvImporter $importer): JsonResponse
    {
        $file = $request->files->get('file');
        if (null === $file || !$file->isValid() || $file->getSize() > 4_000_000) {
            throw new ValidationException(['file' => 'Choose a CSV file up to 4 MB.']);
        }
        try {
            $report = $importer->import((string) file_get_contents($file->getPathname()), $this->community->current(),
                '1' === $request->request->get('update'), '1' === $request->request->get('dry_run'));
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['file' => $e->getMessage()]);
        }
        if ('1' !== $request->request->get('dry_run')) {
            $this->audit->record($admin->id, 'admin#'.$admin->id, 'address.imported', 'address', null, array_diff_key($report, ['skipped' => 1]) + ['skipped' => \count($report['skipped'])], 'admin');
        }

        return new JsonResponse(['report' => $report]);
    }

    #[Route('/claims/{id}/transfer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transfer(#[CurrentUser] User $admin, int $id, Request $request, ClaimAdministration $claims): JsonResponse
    {
        $b = JsonBody::decode($request);
        if (true !== ($b['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm the transfer explicitly.');
        }

        return new JsonResponse(['transfer' => $claims->transfer($id, (string) ($b['email'] ?? ''), $admin->id, isset($b['dispute_id']) ? (int) $b['dispute_id'] : null)]);
    }

    #[Route('/claims/{id}/unclaim', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unclaim(#[CurrentUser] User $admin, int $id, Request $request, ClaimAdministration $claims): JsonResponse
    {
        $b = JsonBody::decode($request);
        if (true !== ($b['confirm'] ?? false)) {
            return ApiError::response(422, 'confirmation_required', 'Confirm the unclaim explicitly.');
        }

        return new JsonResponse(['from_user_id' => $claims->unclaim($id, $admin->id, (string) ($b['reason'] ?? ''))]);
    }

    #[Route('/listings/{id}/hide', requirements: ['id' => '\d+'], methods: ['POST'], name: 'admin_listing_hide')]
    #[Route('/listings/{id}/unhide', requirements: ['id' => '\d+'], methods: ['POST'], name: 'admin_listing_unhide')]
    public function moderate(#[CurrentUser] User $admin, int $id, Request $request, ClaimAdministration $claims): JsonResponse
    {
        $hide = 'admin_listing_hide' === $request->attributes->get('_route'); // the matched route, never the raw path
        $claims->moderate($id, $hide, (string) (JsonBody::decode($request)['reason'] ?? ''), $admin->id);

        return new JsonResponse(['hidden' => $hide]);
    }

    #[Route('/disputes', methods: ['GET'])]
    public function disputes(Request $request, DisputeService $disputes): JsonResponse
    {
        $status = $request->query->get('status');

        return new JsonResponse(['disputes' => array_map(static fn (array $d) => $d + ['ticket' => DisputeService::ticket((int) $d['id'])],
            $disputes->list(\in_array($status, ['open', 'resolved', 'rejected'], true) ? $status : null))]);
    }

    #[Route('/disputes/{id}', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resolveDispute(#[CurrentUser] User $admin, int $id, Request $request, DisputeService $disputes): JsonResponse
    {
        $b = JsonBody::decode($request);
        if (!$disputes->resolve($id, (string) ($b['status'] ?? ''), (string) ($b['note'] ?? ''), $admin->id)) {
            return ApiError::response(404, 'not_found', 'Unknown dispute.');
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/audit', methods: ['GET'])]
    public function auditLog(Request $request): JsonResponse
    {
        return new JsonResponse(['events' => $this->audit->recent(max(1, min(1000, (int) $request->query->get('limit', 200))))]);
    }

    /** A POST (with the CSRF token as a form field): the export writes an audit event, so no link or prefetch may trigger it. */
    #[Route('/export/claims.csv', methods: ['POST'])]
    public function exportClaims(#[CurrentUser] User $admin, ClaimAdministration $claims): Response
    {
        $this->audit->record($admin->id, 'admin#'.$admin->id, 'claims.exported', 'claims', null, [], 'admin');

        return new Response($claims->exportCsv(), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="claims-'.$this->community->current()->eventDate().'.csv"']);
    }

    /**
     * What the console needs to know about an event type: its name, feature switches and console labels.
     *
     * @return array{key:string, name:string, features:list<array{key:string,label:string}>, copy:array<string,string>}
     */
    private function typeView(EventType $t): array
    {
        $copy = $t->copy();

        return [
            'key' => $t->key(),
            'name' => $t->defaultName(),
            'schedule_kind' => $t->scheduleKind(),
            'features' => array_map(static fn (string $k, array $f) => ['key' => $k, 'label' => $f['label']], array_keys($t->features()), array_values($t->features())),
            'copy' => ['access_gated_open' => $copy['admin_access_gated_open'], 'reminders_label' => $copy['admin_reminders_label'], 'reminders_help' => $copy['admin_reminders_help']],
        ];
    }

    /**
     * @param array<string,mixed> $b
     *
     * @return array{0:float,1:float,2:?string,3:bool}
     */
    private function validatedPoint(array $b): array
    {
        $lat = $b['lat'] ?? null;
        $lon = $b['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            throw new ValidationException(['lat' => 'Latitude and longitude are required (no geocoding).']);
        }
        $c = $this->community->current();
        $zone = isset($b['zone_key']) && '' !== $b['zone_key'] ? (string) $b['zone_key'] : null;
        if (null !== $zone && null === $c->zone($zone)) {
            throw new ValidationException(['zone_key' => 'Unknown zone.']);
        }

        return [(float) $lat, (float) $lon, $zone, $c->insideBoundary((float) $lat, (float) $lon)];
    }
}
