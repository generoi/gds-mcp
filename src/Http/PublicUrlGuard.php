<?php

namespace GeneroWP\MCP\Http;

use WpOrg\Requests\Exception as RequestsException;

/**
 * Keeps server-side fetches on public https hosts, on every hop.
 *
 * wp_safe_remote_get() checks the first URL and each redirect, but resolves
 * only the host's IPv4 address, so a host whose AAAA record points at ::1, a
 * unique-local or a link-local address passes, and cURL may connect to it.
 * Older WordPress releases also missed 169.254/16 and 100.64/10.
 * {@see guard()} checks each request Requests sends — the first one and every
 * redirect it follows — against all of the host's A and AAAA addresses, and
 * aborts unless every one is public.
 */
final class PublicUrlGuard
{
    /**
     * Ranges that are not reachable on the public internet.
     */
    private const NON_PUBLIC = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/3',
        '::/127', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /**
     * Resolves a host to all its addresses. Tests swap in a stub so they need
     * no DNS.
     *
     * @var (\Closure(string): list<string>)|null
     */
    public static ?\Closure $resolver = null;

    /**
     * Run an HTTP call with every request it makes restricted to public
     * https URLs. A refused hop comes back from the WP HTTP API as a WP_Error.
     *
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    public static function guard(callable $request): mixed
    {
        $check = static function ($url): void {
            $error = self::urlError((string) $url);
            if ($error !== null) {
                throw new RequestsException($error, 'gds_mcp_url_not_public');
            }
        };

        add_action('requests-requests.before_request', $check);
        try {
            return $request();
        } finally {
            remove_action('requests-requests.before_request', $check);
        }
    }

    /**
     * Why a URL may not be fetched, or null when it may.
     */
    public static function urlError(string $url): ?string
    {
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return sprintf('Only https URLs can be fetched (got %s).', $url);
        }
        $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '[].'));
        if ($host === '') {
            return 'URL has no host component.';
        }

        // The site itself, as wp_http_validate_url() allows.
        if ($host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ?? self::resolve(...))($host);
        if (! $ips) {
            return sprintf('Could not resolve %s.', $host);
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return sprintf('%s resolves to a private or reserved address (%s), which cannot be fetched.', $host, $ip);
            }
        }

        return null;
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) addresses
        // reach the IPv4 address in their last 32 bits.
        if (strlen($packed) === 16
            && (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8)))) {
            return self::isPublicIp((string) inet_ntop(substr($packed, 12)));
        }

        foreach (self::NON_PUBLIC as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = (string) inet_pton($network);
        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        if (substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
            return false;
        }
        $remainder = (int) $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return $ips;
    }
}
