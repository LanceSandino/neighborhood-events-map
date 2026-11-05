<?php

declare(strict_types=1);

namespace App\Mail;

use App\Community\BrandPalette;
use App\Community\CommunityConfig;
use App\Community\LogoStore;
use App\Event\EventTypeRegistry;
use App\Shared\RuntimeConfig;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * @module Mail/Notifier
 * @description Sends the transactional emails (one-time sign-in/claim link, organizer dispute
 *     notice) through the configured SMTP transport. Links are built from APP_BASE_URL only. An account is the
 *     community's, so the sign-in link names only the community; a claim link names the event of the listing it
 *     confirms ("… <event> listing for <address>") and a review request the event of the page it came from (the selected
 *     event, ADR 0012). The HTML email opens with the logo of what it concerns, else its drawn mark (an event's logo, else
 *     its type's mark; the community's logo, else the neutral mark), as a bitmap by absolute URL because mail clients do
 *     not draw SVG: an SVG logo gives way to the mark. Its button takes the event's colour, or the neutral one. The sender
 *     is MAIL_FROM.
 *
 * @dependencies symfony/mailer, Twig templates under templates/email/, Community/LogoStore, Community/BrandPalette, Event/EventTypeRegistry
 */
final class Notifier
{
    /** The sign-in email is the community's: neutral, on no event's colours. */
    private const NEUTRAL_BUTTON = '#38465C';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RuntimeConfig $config,
        private readonly LogoStore $logos,
        private readonly EventTypeRegistry $types,
    ) {
    }

    public function sendMagicLink(string $email, string $rawToken, int $minutes, CommunityConfig $community, ?string $claimAddress): void
    {
        $url = rtrim($this->config->baseUrl, '/').'/auth/confirm?t='.rawurlencode($rawToken);
        $message = (new TemplatedEmail())
            ->from(Address::create($this->config->mailFrom))
            ->to(new Address($email)) // never re-parsed as "name <address>"
            ->subject(null !== $claimAddress
                ? sprintf('Confirm your %s %s listing for %s', $community->name(), $community->eventName(), $claimAddress)
                : sprintf('Your %s sign-in link', $community->name()))
            ->htmlTemplate('email/magic_link.html.twig')
            ->textTemplate('email/magic_link.txt.twig')
            ->context(['url' => $url, 'minutes' => $minutes, 'community' => $community->name(),
                'event' => null !== $claimAddress ? $community->eventName() : '',
                'claim_address' => $claimAddress] + $this->look($community, null !== $claimAddress));
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        $this->mailer->send($message);
    }

    /**
     * The picture and colours of an email about an event, or (false) about the community.
     *
     * @return array{mark_url:string, button:string, button_text:string}
     */
    private function look(CommunityConfig $community, bool $aboutEvent): array
    {
        $event = $aboutEvent ? $community->event : null;
        $base = rtrim($this->config->baseUrl, '/');
        $mark = null !== $event ? (string) ($this->types->get($event->type)->copy()['page_email_mark'] ?? '/assets/img/email/brand-community.png') : '/assets/img/email/brand-community.png';
        $logo = $this->logos->meta($event?->id);
        $picture = null !== $logo && 'svg' !== $logo['extension'] ? (string) $this->logos->url($event?->id) : $mark;
        $color = null !== $event && \is_string($community->settings['branding']['primary_color'] ?? null) ? $community->settings['branding']['primary_color'] : self::NEUTRAL_BUTTON;

        return ['mark_url' => $base.$picture, 'button' => $color, 'button_text' => BrandPalette::ink($color)];
    }

    /** @param array<string,mixed> $dispute */
    public function sendDisputeNotice(array $dispute, string $address, CommunityConfig $community): void
    {
        $message = (new TemplatedEmail())
            ->from(Address::create($this->config->mailFrom))
            ->to($this->config->adminEmail)
            ->replyTo(new Address((string) $dispute['reporter_email']))
            ->subject(sprintf('[%s %s] Review request D-%06d for %s', $community->name(), $community->eventName(), $dispute['id'], $address))
            ->textTemplate('email/dispute_notice.txt.twig')
            ->context(['dispute' => $dispute, 'address' => $address, 'community' => $community->name(), 'event' => $community->eventName(),
                'admin_url' => rtrim($this->config->baseUrl, '/').'/admin/disputes']);
        $this->mailer->send($message);
    }
}
