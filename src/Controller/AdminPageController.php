<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\CommunityConfig;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use App\Community\TypeDefaults;
use App\Event\EventOccurrence;
use App\Event\EventOccurrenceRepository;
use App\Event\EventTypeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/AdminPageController
 * @description Organizer console page shells (ROLE_ADMIN). Data loads from /api/admin/* via vanilla JS. The draft
 *     preview renders the public page for a draft event without any homes, with the draft type's theme, words,
 *     filters and feature switches (Community/TypeDefaults).
 */
#[IsGranted('ROLE_ADMIN')] // also enforced by access_control; declared here so a route rule is never the only lock
final class AdminPageController extends AbstractController
{
    private const PAGES = ['overview' => 'Overview', 'events' => 'Events', 'community' => 'Community & branding', 'map' => 'Boundary & zones', 'addresses' => 'Addresses & claims', 'disputes' => 'Review requests', 'audit' => 'Audit log'];

    #[Route('/admin', name: 'admin', defaults: ['page' => 'overview'], methods: ['GET'])]
    #[Route('/admin/events', name: 'admin_events_page', defaults: ['page' => 'events'], methods: ['GET'])]
    #[Route('/admin/community', name: 'admin_community', defaults: ['page' => 'community'], methods: ['GET'])]
    #[Route('/admin/community/map', name: 'admin_map', defaults: ['page' => 'map'], methods: ['GET'])]
    #[Route('/admin/addresses', name: 'admin_addresses', defaults: ['page' => 'addresses'], methods: ['GET'])]
    #[Route('/admin/disputes', name: 'admin_disputes', defaults: ['page' => 'disputes'], methods: ['GET'])]
    #[Route('/admin/audit', name: 'admin_audit', defaults: ['page' => 'audit'], methods: ['GET'])]
    public function page(string $page, CommunityRepository $community, CommunityPresenter $presenter): Response
    {
        return $this->render('admin/'.$page.'.html.twig', [
            'c' => $presenter->publicPayload($community->current(), []),
            'page' => $page,
            'pages' => self::PAGES,
        ]);
    }

    /**
     * A draft event as visitors will see it: the public page with the draft's type, words and dates and no homes.
     * Organizers only (this controller's role and access_control); never public, never cached.
     */
    #[Route('/admin/events/{id}/preview', name: 'admin_event_preview', requirements: ['id' => '\d{1,12}'], methods: ['GET'])]
    public function preview(int $id, CommunityRepository $community, CommunityPresenter $presenter, EventOccurrenceRepository $occurrences, EventTypeRegistry $types): Response
    {
        $draft = $occurrences->find($id);
        if (null === $draft || EventOccurrence::DRAFT !== $draft->state || !$types->isEnabled($draft->type)) {
            throw $this->createNotFoundException();
        }
        $c = $community->current();
        $settings = $c->settings;
        $settings['event'] = ['name' => $draft->name, 'date' => $draft->startsOn, 'starts_at' => $draft->defaultStart, 'ends_at' => $draft->defaultEnd,
            'type' => $draft->type, 'starts_on' => $draft->startsOn, 'ends_on' => $draft->endsOn];
        // The draft's type decides the theme, words, filters and feature switches, as it will once it is on the map.
        $settings = TypeDefaults::apply($settings, $types->get($draft->type), $types);
        $payload = $presenter->publicPayload(new CommunityConfig($settings, $c->boundary, $c->boundaryApproved, $c->boundaryVersion, $c->zoneShapes, $c->configured, $draft), []);
        $response = $this->render('admin/event_preview.html.twig', ['c' => $payload, 'homes' => [], 'boot' => ['community' => $payload, 'homes' => []]]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
