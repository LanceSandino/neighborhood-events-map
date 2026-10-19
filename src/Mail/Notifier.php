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
            ->to($email)
            ->subject(null !== $claimAddress
                ? sprintf('Confirm your %s Halloween listing', $community->name())
                : sprintf('Your %s Halloween sign-in link', $community->name()))
            ->htmlTemplate('email/magic_link.html.twig')
            ->textTemplate('email/magic_link.txt.twig')
            ->context(['url' => $url, 'minutes' => $minutes, 'community' => $community->name(), 'claim_address' => $claimAddress]);
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        $this->mailer->send($message);
    }

    /** @param array<string,mixed> $dispute */
    public function sendDisputeNotice(array $dispute, string $address, CommunityConfig $community): void
    {
        $message = (new TemplatedEmail())
            ->from(Address::create($this->config->mailFrom))
            ->to($this->config->adminEmail)
            ->replyTo($dispute['reporter_email'])
            ->subject(sprintf('[%s Halloween] Review request D-%06d for %s', $community->name(), $dispute['id'], $address))
            ->textTemplate('email/dispute_notice.txt.twig')
            ->context(['dispute' => $dispute, 'address' => $address, 'community' => $community->name(),
                'admin_url' => rtrim($this->config->baseUrl, '/').'/admin/disputes']);
        $this->mailer->send($message);
    }
}
