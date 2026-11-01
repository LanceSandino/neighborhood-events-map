<?php

declare(strict_types=1);

namespace App\Controller;

use App\About\ChangelogReader;
use App\Community\BrandingStore;
use App\Community\BrandPalette;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use App\Event\EventTypeRegistry;
use App\Listing\ListingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @module Controller/PageController
 * @description Server-rendered pages: for the selected event (`?e=<id>`) or the default one, the public map shell
 *     with a no-JS readable list of homes (open), the event's dates and a way to list a home (upcoming) or its
 *     thank-you message (closed), each with the event switcher; with no public event a neutral "no event right now"
 *     page; sign-in page, owner dashboard shell, theme CSS from config (the selected event's type; My house's own,
 *     residents only, also for a hidden event it may name, D-20261101-01), uploaded logo, health check.
 *
 * @dependencies Community/*, Listing/ListingRepository, Event/EventTypeRegistry, About/ChangelogReader
 */
final class PageController extends AbstractController
{
    public function __construct(
        private readonly CommunityRepository $community,
        private readonly CommunityPresenter $presenter,
        private readonly ListingRepository $listings,
        private readonly EventTypeRegistry $types,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $c = $this->community->current();
        if (null === $c->event) {
            // Between events (or an event this release does not offer): a neutral page, no listings, no map scripts.
            return $this->render('home/no_event.html.twig', ['c' => $this->presenter->publicPayload($c, [])]);
        }
        if (!$c->showsHomes()) {
            // Upcoming (its dates, residents may list) or closed (the thank-you message): no homes, no map.
            $payload = $this->presenter->publicPayload($c, []);

            return $this->render('home/event_status.html.twig', ['c' => $payload, 'boot' => ['community' => $payload, 'homes' => []]]);
        }
        $homes = $this->listings->publicHomes($c, $this->types->for($c));
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

    /** Privacy notice, terms of use and accessibility statement (no data collected to view them). */
    #[Route('/about', name: 'about', defaults: ['page' => 'about'], methods: ['GET'])]
    #[Route('/privacy', name: 'privacy', defaults: ['page' => 'privacy'], methods: ['GET'])]
    #[Route('/terms', name: 'terms', defaults: ['page' => 'terms'], methods: ['GET'])]
    #[Route('/accessibility', name: 'accessibility', defaults: ['page' => 'accessibility'], methods: ['GET'])]
    public function legal(string $page): Response
    {
        $c = $this->community->current();

        return $this->render('legal/'.$page.'.html.twig', ['c' => $this->presenter->publicPayload($c, [])]);
    }

    #[Route('/changelog', name: 'changelog', methods: ['GET'])]
    public function changelog(ChangelogReader $changelog): Response
    {
        return $this->render('legal/changelog.html.twig', ['c' => $this->presenter->publicPayload($this->community->current(), []), 'releases' => $changelog->latest(6)]);
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
        $css = BrandPalette::css($b['primary_color'], $b['accent_color']);

        return new Response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }

    /** My house's colours: the same as /theme.css, for the event My house names — a hidden one too (residents only, never cached). */
    #[Route('/me/theme.css', name: 'me_theme_css', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function meTheme(): Response
    {
        $b = $this->community->current()->settings['branding'];

        return new Response(BrandPalette::css($b['primary_color'], $b['accent_color']), 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'no-store, private']);
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
