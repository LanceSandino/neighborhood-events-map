<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\BrandingStore;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use App\Listing\ListingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @module Controller/PageController
 * @description Server-rendered pages: public map shell (with a no-JS readable list of homes),
 *     sign-in page, owner dashboard shell, theme CSS from config, uploaded logo, health check.
 */
final class PageController extends AbstractController
{
    public function __construct(
        private readonly CommunityRepository $community,
        private readonly CommunityPresenter $presenter,
        private readonly ListingRepository $listings,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $c = $this->community->current();
        $homes = $this->listings->publicHomes($c->eventDate());
        $payload = $this->presenter->publicPayload($c, $homes);

        return $this->render('home/index.html.twig', ['c' => $payload, 'homes' => $homes, 'boot' => ['community' => $payload, 'homes' => $homes]]);
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        $c = $this->community->current();

        return $this->render('auth/login.html.twig', [
            'c' => $this->presenter->publicPayload($c, []),
            'expired' => '1' === $request->query->get('expired'),
        ]);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): Response
    {
        $c = $this->community->current();

        return $this->render('me/index.html.twig', ['c' => $this->presenter->publicPayload($c, [])]);
    }

    #[Route('/theme.css', name: 'theme_css', methods: ['GET'])]
    public function theme(): Response
    {
        $b = $this->community->current()->settings['branding'];
        $css = sprintf(":root{--brand:%s;--brand-accent:%s}\n", $b['primary_color'], $b['accent_color']);

        return new Response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }

    #[Route('/branding/{name}', name: 'branding', requirements: ['name' => 'logo\.(svg|png|jpg|webp)'], methods: ['GET'])]
    public function branding(string $name, BrandingStore $store): Response
    {
        $file = $store->find($name);
        if (null === $file) {
            throw $this->createNotFoundException();
        }
        $r = new BinaryFileResponse($file['path'], 200, ['Content-Type' => $file['type'], 'Cache-Control' => 'public, max-age=3600']);
        $r->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        return $r;
    }

    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function health(Connection $db): JsonResponse
    {
        try {
            $db->fetchOne('SELECT 1');
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return new JsonResponse(['status' => $ok ? 'ok' : 'degraded', 'database' => $ok ? 'ok' : 'unavailable', 'version' => $this->getParameter('app.version')], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
