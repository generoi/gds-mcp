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
 * aborts unless every one is public. cURL is then made to connect to an
 * address that was checked, so a second DNS answer (rebinding) or a URL that
 * cURL parses differently from PHP cannot send it elsewhere.
 */
final class PublicUrlGuard
{
    /**
     * Ranges that are not reachable on the public internet, or that carry
     * another address inside them (6to4, Teredo, NAT64).
     */
    private const NON_PUBLIC = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/3',
        '::/96', '100::/64', '2001::/32', '2002::/16', '64:ff9b:1::/48',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * The message every refused URL gets: which internal address a host
     * resolves to is nothing a caller should learn from it.
     */
    private const REFUSED = 'This URL cannot be fetched: only public https addresses are allowed.';

    /**
     * Test seam: resolves a host to ['v4' => [...], 'v6' => [...], 'v6_failed' => bool].
     *
     * @var (\Closure(string): array{v4: list<string>, v6: list<string>, v6_failed: bool})|null
     */
    private static ?\Closure $resolver = null;

    /**
     * Replace DNS for a test. Refused outside PHPUnit, so nothing in
     * production can swap the lookup the guard relies on.
     *
     * @internal
     *
     * @param  (\Closure(string): array{v4: list<string>, v6: list<string>, v6_failed: bool})|null  $resolver
     */
    public static function resolveForTests(?\Closure $resolver): void
    {
        if (! class_exists(\PHPUnit\Framework\TestCase::class, false)) {
            throw new \LogicException('PublicUrlGuard::resolveForTests() is for tests only.');
        }
        self::$resolver = $resolver;
    }

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
        // The address the hop about to be sent was checked against. Requests
        // fires before_request, then hands the hop to cURL, one hop at a time.
        $pin = null;

        $check = static function ($url) use (&$pin): void {
            $pin = self::check((string) $url);
            if ($pin === false) {
                throw new RequestsException(self::REFUSED, 'gds_mcp_url_not_public');
            }
        };
        $connectToChecked = static function ($handle) use (&$pin): void {
            if (! is_array($pin)) {
                return;
            }
            $ip = str_contains($pin['ip'], ':') ? "[{$pin['ip']}]" : $pin['ip'];
            // An empty host and port match whatever cURL parsed from the URL.
            $pinned = curl_setopt($handle, CURLOPT_CONNECT_TO, ["::{$ip}:{$pin['port']}"])
                && (! $pin['v4_only'] || curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4));
            if (! $pinned) {
                throw new RequestsException(self::REFUSED, 'gds_mcp_url_not_public');
            }
        };

        add_action('requests-requests.before_request', $check);
        add_action('requests-curl.before_send', $connectToChecked);
        try {
            return $request();
        } finally {
            remove_action('requests-requests.before_request', $check);
            remove_action('requests-curl.before_send', $connectToChecked);
        }
    }

    /**
     * Why a URL may not be fetched, or null when it may.
     */
    public static function urlError(string $url): ?string
    {
        return self::check($url) === false ? self::REFUSED : null;
    }

    /**
     * Check a URL: false when it may not be fetched, null when it may and
     * needs no pin (the site itself), else the checked address to connect to.
     *
     * @return array{ip: string, port: int, v4_only: bool}|false|null
     */
    private static function check(string $url): array|false|null
    {
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return self::refuse("{$url} is not https");
        }
        $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '[].'));
        if ($host === '') {
            return self::refuse("{$url} has no host");
        }

        // The site itself, as wp_http_validate_url() allows.
        if ($host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = str_contains($host, ':')
                ? ['v4' => [], 'v6' => [$host], 'v6_failed' => false]
                : ['v4' => [$host], 'v6' => [], 'v6_failed' => false];
        } else {
            $addresses = (self::$resolver ?? self::resolve(...))($host);
        }

        // A failed AAAA lookup hides what cURL might connect to over IPv6;
        // only go on over IPv4, and only if there is an IPv4 address.
        if ($addresses['v6_failed'] && ! $addresses['v4']) {
            return self::refuse("{$host}: AAAA lookup failed and no A record");
        }
        $ips = $addresses['v6_failed'] ? $addresses['v4'] : [...$addresses['v4'], ...$addresses['v6']];
        if (! $ips) {
            return self::refuse("{$host} does not resolve");
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return self::refuse("{$host} resolves to {$ip}");
            }
        }

        return [
            'ip' => $ips[0],
            'port' => (int) (wp_parse_url($url, PHP_URL_PORT) ?: 443),
            'v4_only' => $addresses['v6_failed'],
        ];
    }

    private static function refuse(string $detail): false
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('gds-mcp refused a server-side fetch: '.$detail);
        }

        return false;
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
     * @return array{v4: list<string>, v6: list<string>, v6_failed: bool}
     */
    private static function resolve(string $host): array
    {
        // dns_get_record() is false when the lookup failed, [] when the host
        // has no AAAA record.
        $records = @dns_get_record($host, DNS_AAAA);
        $v6 = [];
        foreach ($records ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $v6[] = (string) $record['ipv6'];
            }
        }

        return [
            'v4' => gethostbynamel($host) ?: [],
            'v6' => $v6,
            'v6_failed' => $records === false,
        ];
    }
}
