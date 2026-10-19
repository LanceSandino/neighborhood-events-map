<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/AdminPageController
 * @description Organizer console page shells (ROLE_ADMIN). Data loads from /api/admin/* via vanilla JS.
 */
#[IsGranted('ROLE_ADMIN')] // also enforced by access_control; declared here so a route rule is never the only lock
final class AdminPageController extends AbstractController
{
    private const PAGES = ['overview' => 'Overview', 'community' => 'Community & branding', 'map' => 'Boundary & zones', 'addresses' => 'Addresses & claims', 'disputes' => 'Review requests', 'audit' => 'Audit log'];

    #[Route('/admin', name: 'admin', defaults: ['page' => 'overview'], methods: ['GET'])]
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
}
