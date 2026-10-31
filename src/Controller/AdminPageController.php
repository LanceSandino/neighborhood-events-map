<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\BrandPalette;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
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
 *     filters and feature switches and the draft's own branding (Community/CommunityRepository::forEvent), its colours
 *     from an organizer-only stylesheet. The console pages themselves are the community's (D-20261031-52).
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
        // The community as the draft shows it: its type's theme, words, filters and switches and its own branding, as
        // once it is on the map.
        $payload = $presenter->publicPayload($community->forEvent($draft), []);
        $response = $this->render('admin/event_preview.html.twig', ['c' => $payload, 'homes' => [], 'boot' => ['community' => $payload, 'homes' => []]]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /** A draft's own colours for its preview (BrandPalette over its branding and type); organizers only, never cached. */
    #[Route('/admin/events/{id}/theme.css', name: 'admin_event_theme', requirements: ['id' => '\d{1,12}'], methods: ['GET'])]
    public function previewTheme(int $id, CommunityRepository $community, EventOccurrenceRepository $occurrences, EventTypeRegistry $types): Response
    {
        $draft = $occurrences->find($id);
        if (null === $draft || EventOccurrence::DRAFT !== $draft->state || !$types->isEnabled($draft->type)) {
            throw $this->createNotFoundException();
        }
        $b = $community->forEvent($draft)->settings['branding'];

        return new Response(BrandPalette::css($b['primary_color'], $b['accent_color']), 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }
}
