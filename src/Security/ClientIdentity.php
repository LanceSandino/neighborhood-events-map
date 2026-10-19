<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\RuntimeConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * @module Security/ClientIdentity
 * @description Derives keyed (HMAC) hashes of client IPs and other identifiers so throttles and
 *     PoW binding never store raw IP addresses or emails. IPv6 clients are keyed by their /64
 *     network (one subscriber or server allocation), IPv4-mapped addresses by the IPv4 address.
 */
final class ClientIdentity
{
    public function __construct(private readonly RuntimeConfig $config)
    {
    }

    /** The client key for rate limits and proof-of-work binding (an IPv4 address or an IPv6 /64). */
    public function ip(Request $request): string
    {
        return self::networkKey($request->getClientIp() ?? '0.0.0.0');
    }

    public static function networkKey(string $ip): string
    {
        $packed = @inet_pton($ip);
        if (false === $packed || 16 !== \strlen($packed)) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    public function hash(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind.'|'.$value, $this->config->appSecret);
    }

    public function ipHash(Request $request): string
    {
        return $this->hash('ip', $this->ip($request));
    }
}
