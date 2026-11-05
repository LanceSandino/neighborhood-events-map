<?php

declare(strict_types=1);

namespace App\Controller;

use App\About\ChangelogReader;
use App\Community\LogoStore;
use App\Community\BrandPalette;
use App\Community\CommunityPresenter;
use App\Community\CommunityRepository;
use App\Event\EventOccurrenceRepository;
use App\Event\EventTypeRegistry;
use App\Listing\ListingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 *     page; the list of past events; sign-in page, owner dashboard shell, theme CSS from config (the selected event's type; My house's own,
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

    /** The closed events, newest first, each leading to its thank-you page (D-20261104-02). */
    #[Route('/past-events', name: 'past_events', methods: ['GET'])]
    public function pastEvents(): Response
    {
        return $this->render('home/past_events.html.twig', ['c' => $this->presenter->publicPayload($this->community->current(), [])]);
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

    /** The community's logo, from the database; its URL's ?v= changes with its content. */
    #[Route('/branding/logo.{extension}', name: 'branding', requirements: ['extension' => 'svg|png|jpg|webp'], methods: ['GET'])]
    public function branding(string $extension, Request $request, LogoStore $logos): Response
    {
        return $this->logoResponse($logos->load(null), $extension, $request, true);
    }

    /**
     * An event's logo. A visitor gets one only for a public event; a hidden event is exactly an unknown id to them
     * (D-20261101-01), though residents may see it in My house, and drafts and archived events are organizers' only.
     */
    #[Route('/branding/event-{id}/logo.{extension}', name: 'branding_event', requirements: ['id' => '\d{1,12}', 'extension' => 'svg|png|jpg|webp'], methods: ['GET'])]
    public function eventBranding(int $id, string $extension, Request $request, LogoStore $logos, EventOccurrenceRepository $occurrences): Response
    {
        $event = $occurrences->find($id);
        $public = null !== $event && $event->isPublic();
        if (null === $event || (!$public && !$this->isGranted($event->isForResidents() ? 'ROLE_USER' : 'ROLE_ADMIN'))) {
            throw $this->createNotFoundException();
        }

        return $this->logoResponse($logos->load($id), $extension, $request, $public);
    }

    /**
     * A logo with its sandboxing policy. A request with the current version in its URL is cached for a long time, since a
     * changed logo has another URL; anything else only briefly, and a logo that is not public never.
     *
     * @param array{extension:string, type:string, bytes:string, sha256:string}|null $logo
     */
    private function logoResponse(?array $logo, string $extension, Request $request, bool $public): Response
    {
        if (null === $logo || $logo['extension'] !== $extension) {
            throw $this->createNotFoundException();
        }
        $cache = !$public ? 'private, no-store' : (substr($logo['sha256'], 0, 10) === $request->query->get('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=300');
        $r = new Response($logo['bytes'], 200, ['Content-Type' => $logo['type'], 'Cache-Control' => $cache]);
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
