<?php

declare(strict_types=1);

namespace App\Mail;

use App\Community\CommunityConfig;
use App\Shared\RuntimeConfig;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * @module Mail/Notifier
 * @description Sends the transactional emails (one-time sign-in/claim link, organizer dispute
 *     notice) through the configured SMTP transport. Links are built from APP_BASE_URL only.
 *
 * @dependencies symfony/mailer, Twig templates under templates/email/
 */
final class Notifier
{
    public function __construct(private readonly MailerInterface $mailer, private readonly RuntimeConfig $config)
    {
    }

    public function sendMagicLink(string $email, string $rawToken, int $minutes, CommunityConfig $community, ?string $claimAddress): void
    {
        $url = rtrim($this->config->baseUrl, '/').'/auth/confirm?t='.rawurlencode($rawToken);
        $message = (new TemplatedEmail())
            ->from(Address::create($this->config->mailFrom))
            ->to(new Address($email)) // never re-parsed as "name <address>"
            ->subject(null !== $claimAddress
                ? sprintf('Confirm your %s %s listing', $community->name(), $community->eventName())
                : sprintf('Your %s %s sign-in link', $community->name(), $community->eventName()))
            ->htmlTemplate('email/magic_link.html.twig')
            ->textTemplate('email/magic_link.txt.twig')
            ->context(['url' => $url, 'minutes' => $minutes, 'community' => $community->name(), 'event' => $community->eventName(),
                'emoji' => $community->settings['branding']['brand_emoji'] ?? '', 'claim_address' => $claimAddress]);
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        $this->mailer->send($message);
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
