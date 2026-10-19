<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\BrandingStore;
use App\Community\ConfigException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Traceability: BLUEPRINT §8 (consistent JSON errors 400/401/403/404/409/422/429), §5 (secure headers),
 * resilience when SMTP is down (no silent success), config-only feature switches.
 */
final class ErrorPathsTest extends AppTestCase
{
    public function testJsonErrorEnvelopes(): void
    {
        $this->configureCommunity();
        $this->client->request('POST', '/api/auth/request-link', [], [], ['CONTENT_TYPE' => 'application/json'], '{"email":');
        self::assertSame(400, $this->code());
        self::assertSame('bad_json', json_decode((string) $this->client->getResponse()->getContent(), true)['error']['code']);
        $this->client->request('POST', '/api/auth/request-link', [], [], ['CONTENT_TYPE' => 'application/json'], '"just a string"');
        self::assertSame(422, $this->code());
        $this->client->request('POST', '/api/auth/request-link', [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 300_000));
        self::assertSame(422, $this->code(), 'oversized body');
        $this->json('GET', '/api/nope');
        self::assertSame('not_found', json_decode((string) $this->client->getResponse()->getContent(), true)['error']['code']);
        $this->json('DELETE', '/api/homes');
        self::assertSame(405, $this->code());
        $this->json('GET', '/api/homes/999999');
        self::assertSame(404, $this->code());
        $this->json('GET', '/api/addresses/1/claim-status');
        self::assertSame(404, $this->code(), 'no id-based registry probe endpoint');
        $this->json('GET', '/api/pow?purpose=evil');
        self::assertSame(400, $this->code());
        $this->json('GET', '/_internal_logout');
        self::assertSame(404, $this->code());
    }

    public function testInvalidEmailsAreRejectedAfterPow(): void
    {
        $this->configureCommunity();
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'not-an-email', 'pow' => $this->solvePow('login')]);
        self::assertSame(422, $this->code());
        self::assertArrayHasKey('email', $r['error']['fields']);
        $r = $this->json('POST', '/api/disputes', ['address_id' => 1, 'reporter_email' => '', 'reason' => str_repeat('x', 250), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(422, $this->code());
        self::assertArrayHasKey('reporter_email', $r['error']['fields']);
    }

    public function testMailOutageIsReportedNotHidden(): void
    {
        $this->configureCommunity();
        $this->client->disableReboot();
        static::getContainer()->set(MailerInterface::class, new class() implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('connection refused');
            }
        });
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'down@example.test', 'pow' => $this->solvePow('login')]);
        self::assertSame(503, $this->code());
        self::assertSame('mail_unavailable', $r['error']['code']);
    }

    public function testHttpsOnlyHeadersAndSubdomainTileHosts(): void
    {
        $this->configureCommunity(['map' => ['tiles' => ['url' => 'https://{s}.tile.example.org/{z}/{x}/{y}.png', 'attribution' => '© Example']]]);
        $this->client->request('GET', '/', [], [], ['HTTPS' => 'on']);
        $h = $this->client->getResponse()->headers;
        self::assertStringContainsString('max-age=31536000', (string) $h->get('Strict-Transport-Security'));
        self::assertSame('same-origin', $h->get('Cross-Origin-Opener-Policy'));
        self::assertStringContainsString('https://*.tile.example.org', (string) $h->get('Content-Security-Policy'));
        $this->client->request('GET', 'http://localhost/');
        self::assertNull($this->client->getResponse()->headers->get('Strict-Transport-Security'));
    }

    public function testDisabledFeaturesDisappearFromUiAndData(): void
    {
        $this->configureCommunity(['features' => ['adult_refreshments' => false, 'nonfood_treats' => false, 'candy' => false, 'sensory_filters' => false]]);
        $html = (string) $this->client->request('GET', '/')->html();
        foreach (['Adult refreshments · 21+', 'btnCandy', 'btnQuiet', 'No jump scares'] as $hidden) {
            self::assertStringNotContainsString($hidden, $html);
        }
        $id = $this->addAddress('3', 'Lantern Way', 40.005, -74.995, 'east');
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'f@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['address_id' => $id], 'listing' => ['candy' => true, 'adult_drinks' => true, 'nonfood' => true]]]);
        self::assertSame(422, $this->code(), 'every offering disabled → nothing valid to offer');
        self::assertArrayHasKey('offerings', $r['error']['fields']);
    }

    public function testBrandingStoreValidatesRasterImages(): void
    {
        $dir = sys_get_temp_dir().'/brand-'.bin2hex(random_bytes(3));
        $store = new BrandingStore($dir);
        $png = $dir.'-logo.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));
        self::assertSame('logo.png', $store->storeLogo($png));
        self::assertSame('image/png', $store->find('logo.png')['type']);
        self::assertNull($store->find('logo.svg'), 'previous logo files are replaced');
        file_put_contents($png, 'not really a png');
        try {
            $store->storeLogo($png);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString('not a readable image', $e->getMessage());
        }
        // Polyglot: a real PNG header with HTML appended, and a PNG renamed to .jpg, are both refused.
        $realPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        foreach ([[$png, $realPng.'<html><script>alert(1)</script>'], [$dir.'-logo.jpg', $realPng]] as [$path, $content]) {
            file_put_contents($path, $content);
            try {
                $store->storeLogo($path);
                self::fail('expected ConfigException for '.basename($path));
            } catch (ConfigException $e) {
                self::assertStringContainsString('does not match its file type', $e->getMessage());
            }
        }
        @unlink($dir.'-logo.jpg');
        unlink($png);
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}
