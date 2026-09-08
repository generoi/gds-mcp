<?php

namespace GeneroWP\MCP\OAuth;

/**
 * Dynamic client registration (RFC 7591).
 *
 * Claude registers a fresh client on every new connection, so registrations
 * are cheap records that get pruned rather than curated entries. Clients are
 * public — no secret is issued, and PKCE is what binds a code to the client
 * that requested it.
 */
final class Clients
{
    private const OPTION_PREFIX = 'gds_mcp_oauth_client_';

    private const INDEX_OPTION = 'gds_mcp_oauth_clients';

    /**
     * Registrations kept before the oldest are dropped. Generous, because a
     * client whose registration is evicted while someone is still connected
     * to it cannot re-authorize: it is told its client_id is unknown, which
     * is a dead end rather than an instruction to register again.
     */
    private const MAX_CLIENTS = 2000;

    /**
     * Age past which a registration nobody has authorized against is pruned.
     * Measured from last use, so a connection that keeps being re-authorized
     * is never swept up.
     */
    private const MAX_AGE = 60 * DAY_IN_SECONDS;

    /**
     * @param  string|null  $rawBody  Registration body; read from the request when omitted.
     */
    public static function handleRegistration(?string $rawBody = null): void
    {
        Server::handlePreflight('POST');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw Server::error('invalid_request', 'POST required.', 405);
        }

        if (! Server::isSecure()) {
            throw Server::error('invalid_request', 'Client registration requires HTTPS.', 400);
        }

        // RFC 7591 §3.1: the registration request body is JSON.
        $body = json_decode($rawBody ?? (string) file_get_contents('php://input'), true);
        if (! is_array($body)) {
            throw Server::error('invalid_client_metadata', 'Request body must be a JSON object.');
        }

        $redirectUris = array_values(array_filter(
            array_map('strval', (array) ($body['redirect_uris'] ?? [])),
            [self::class, 'isAllowedRedirectUri']
        ));

        if ($redirectUris === []) {
            throw Server::error(
                'invalid_redirect_uri',
                'At least one https:// or loopback redirect_uri is required.'
            );
        }

        $client = [
            'client_id' => 'gmc_'.bin2hex(random_bytes(16)),
            'client_name' => sanitize_text_field((string) ($body['client_name'] ?? 'MCP client')),
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => implode(' ', Metadata::SCOPES),
            'client_id_issued_at' => time(),
        ];

        self::save($client);

        throw ResponseException::json($client, 201);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $clientId): ?array
    {
        if (! str_starts_with($clientId, 'gmc_')) {
            return null;
        }

        $client = get_option(self::OPTION_PREFIX.$clientId);

        return is_array($client) ? $client : null;
    }

    /**
     * Does this redirect URI belong to the client?
     *
     * Loopback URIs match on everything but the port: a native client such as
     * Claude Code binds an ephemeral port per session (RFC 8252 §7.3).
     *
     * @param  array<string, mixed>  $client
     */
    public static function matchRedirectUri(array $client, string $redirectUri): bool
    {
        foreach ($client['redirect_uris'] as $registered) {
            if (hash_equals($registered, $redirectUri)) {
                return true;
            }

            if (self::isLoopback($registered) && self::isLoopback($redirectUri)
                && self::withoutPort($registered) === self::withoutPort($redirectUri)) {
                return true;
            }
        }

        return false;
    }

    public static function isAllowedRedirectUri(string $uri): bool
    {
        $parts = wp_parse_url($uri);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! empty($parts['fragment'])) {
            return false;
        }

        return $parts['scheme'] === 'https' || self::isLoopback($uri);
    }

    private static function isLoopback(string $uri): bool
    {
        $parts = wp_parse_url($uri);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private static function withoutPort(string $uri): string
    {
        $parts = wp_parse_url($uri);

        return ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
    }

    /**
     * Mark a registration as still in use, so pruning measures the age of the
     * connection rather than the age of the registration.
     */
    public static function touch(string $clientId): void
    {
        $index = self::index();
        if (! isset($index[$clientId])) {
            return;
        }

        $index[$clientId] = time();
        update_option(self::INDEX_OPTION, $index, false);
    }

    /**
     * Registration ids mapped to when each was last used.
     *
     * @return array<string, int>
     */
    private static function index(): array
    {
        $index = get_option(self::INDEX_OPTION, []);
        if (! is_array($index)) {
            return [];
        }

        $normalised = [];
        foreach ($index as $key => $value) {
            // Earlier builds stored a plain list of ids with no timestamps.
            // Treat those as used now — an unknown vintage is not a reason to
            // delete a registration somebody may still be connected through —
            // and let the next authorization date them properly.
            [$clientId, $used] = is_int($key) ? [(string) $value, time()] : [(string) $key, (int) $value];
            $normalised[$clientId] = $used;
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private static function save(array $client): void
    {
        add_option(self::OPTION_PREFIX.$client['client_id'], $client, '', false);

        $index = self::index();
        $index[$client['client_id']] = $client['client_id_issued_at'];

        $cutoff = time() - self::MAX_AGE;
        $stale = array_keys(array_filter($index, static fn ($issued): bool => $issued < $cutoff));

        // Only if age alone has not kept the store in check.
        if (count($index) - count($stale) > self::MAX_CLIENTS) {
            asort($index);
            $overflow = array_slice(array_keys($index), 0, count($index) - self::MAX_CLIENTS);
            $stale = array_unique(array_merge($stale, $overflow));
        }

        foreach ($stale as $clientId) {
            delete_option(self::OPTION_PREFIX.$clientId);
            unset($index[$clientId]);
        }

        update_option(self::INDEX_OPTION, $index, false);
    }
}
