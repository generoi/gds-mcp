<?php

namespace GeneroWP\MCP\OAuth;

/**
 * Storage for authorization codes, access tokens, refresh tokens, and the
 * grants that tie them to a WordPress user.
 *
 * Nothing here stores a token in the clear — only SHA-256 hashes, so a leaked
 * database backup cannot be replayed against the MCP endpoint. Tokens carry no
 * claims of their own; every lookup re-reads the grant, which is what makes
 * revocation immediate.
 *
 * Each grant is its own user meta row rather than one array of them all: a
 * revoke and a token refresh arriving together would otherwise both read the
 * whole set and write it back, and whichever wrote last would win. Losing that
 * race means a grant somebody revoked comes back with a fresh 30-day refresh
 * token — at exactly the moment revocation matters most. For the same reason
 * the mutating paths update the row in place and never re-create it.
 */
final class Grants
{
    /**
     * Prefix of the user meta row holding one grant.
     */
    public const META_PREFIX = '_gds_mcp_oauth_grant_';

    public const CODE_TTL = 60;

    public const ACCESS_TTL = HOUR_IN_SECONDS;

    public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

    private const CODE_PREFIX = 'gds_mcp_oauth_code_';

    private const ACCESS_PREFIX = 'gds_mcp_oauth_at_';

    private const REFRESH_PREFIX = 'gds_mcp_oauth_rt_';

    // ── Authorization codes ───────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload  user, client_id, redirect_uri, code_challenge, resource
     */
    public static function issueCode(array $payload): string
    {
        $code = 'gmco_'.self::secret();
        set_transient(self::CODE_PREFIX.self::hash($code), $payload, self::CODE_TTL);

        return $code;
    }

    /**
     * Read and immediately invalidate a code — they are single use.
     *
     * @return array<string, mixed>|null
     */
    public static function consumeCode(string $code): ?array
    {
        $key = self::CODE_PREFIX.self::hash($code);
        $payload = get_transient($key);
        delete_transient($key);

        return is_array($payload) ? $payload : null;
    }

    // ── Grants ───────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $grant  client_id, client_name, resource, scopes
     */
    public static function create(int $userId, array $grant): string
    {
        // Re-authorizing an application is the same connection, not another
        // one. Without this a client that re-consents — Claude registers a
        // fresh client per connection, so this is routine — leaves a row per
        // attempt, all reading the same name, and revoking the one somebody
        // recognises leaves the rest live.
        foreach (self::all($userId) as $existingId => $existing) {
            if ($existing['client_id'] === $grant['client_id']
                && $existing['resource'] === $grant['resource']) {
                self::touch($userId, $existingId);

                return $existingId;
            }
        }

        $grantId = bin2hex(random_bytes(8));

        add_user_meta($userId, self::META_PREFIX.$grantId, $grant + [
            'created' => time(),
            'last_used' => time(),
            'refresh' => null,
        ], true);

        return $grantId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $userId, string $grantId): ?array
    {
        $grant = get_user_meta($userId, self::META_PREFIX.$grantId, true);

        return is_array($grant) ? $grant : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(int $userId): array
    {
        $grants = [];

        foreach (get_user_meta($userId) as $key => $values) {
            if (! str_starts_with((string) $key, self::META_PREFIX)) {
                continue;
            }

            // The keyless form of get_user_meta() returns raw rows.
            $grant = maybe_unserialize((string) ($values[0] ?? ''));
            if (is_array($grant)) {
                $grants[substr((string) $key, strlen(self::META_PREFIX))] = $grant;
            }
        }

        return $grants;
    }

    public static function revoke(int $userId, string $grantId): bool
    {
        $grant = self::get($userId, $grantId);
        if ($grant === null) {
            return false;
        }

        // Every live token the grant holds, not just the most recent: two
        // copies of one application share a grant, and revoking has to end
        // both.
        foreach (self::refreshHashes($grant) as $hash) {
            delete_option(self::REFRESH_PREFIX.$hash);
        }

        return delete_user_meta($userId, self::META_PREFIX.$grantId);
    }

    /**
     * End every connection on the site.
     */
    public static function revokeAll(): void
    {
        foreach (self::users() as $user) {
            foreach (array_keys(self::all($user->ID)) as $grantId) {
                self::revoke($user->ID, (string) $grantId);
            }
        }
    }

    /**
     * Users holding at least one grant, for the admin screen.
     *
     * @return \WP_User[]
     */
    public static function users(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like(self::META_PREFIX).'%'
            )
        );

        return $ids ? get_users(['include' => array_map('intval', $ids)]) : [];
    }

    /**
     * Write a grant back, but only where one is still there.
     *
     * `update_user_meta()` would insert the row if it had been deleted, which
     * is how a revoke loses to a token refresh that started before it.
     *
     * @param  array<string, mixed>  $grant
     */
    private static function update(int $userId, string $grantId, array $grant): bool
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->usermeta,
            ['meta_value' => maybe_serialize($grant)],
            ['user_id' => $userId, 'meta_key' => self::META_PREFIX.$grantId]
        );
        wp_cache_delete($userId, 'user_meta');

        return self::get($userId, $grantId) !== null;
    }

    // ── Access tokens ────────────────────────────────────────────

    public static function issueAccessToken(int $userId, string $grantId): string
    {
        $token = 'gmat_'.self::secret();
        set_transient(
            self::ACCESS_PREFIX.self::hash($token),
            ['user' => $userId, 'grant' => $grantId],
            self::ACCESS_TTL
        );
        self::touch($userId, $grantId);

        return $token;
    }

    /**
     * Resolve a bearer token to its user and grant, or null if it is unknown,
     * expired, or its grant has been revoked.
     *
     * @return array{user: int, grant_id: string, grant: array<string, mixed>}|null
     */
    public static function readAccessToken(string $token): ?array
    {
        $payload = get_transient(self::ACCESS_PREFIX.self::hash($token));
        if (! is_array($payload)) {
            return null;
        }

        $grant = self::get((int) $payload['user'], (string) $payload['grant']);
        if ($grant === null) {
            return null;
        }

        return [
            'user' => (int) $payload['user'],
            'grant_id' => (string) $payload['grant'],
            'grant' => $grant,
        ];
    }

    /**
     * Is the user still allowed to hold a connection at all?
     *
     * The gate is checked when someone consents, but roles change afterwards:
     * an editor who leaves and is demoted to subscriber — rather than deleted,
     * so their posts keep an author — would otherwise keep a working token for
     * as long as the client kept refreshing it.
     *
     * Deliberately not called while resolving the current user: capability
     * checks fire `user_has_cap`, and a plugin listening there that asks who
     * the current user is would re-enter that resolution and never come back.
     */
    public static function mayConnect(int $userId): bool
    {
        return user_can($userId, Server::capability());
    }

    // ── Refresh tokens ───────────────────────────────────────────

    /**
     * Issue a refresh token for a grant.
     *
     * A grant holds a set of live refresh tokens rather than one, because a
     * grant is per application, not per running copy of it: a client whose
     * registration is persisted — a second machine sharing a config file, a
     * second profile, a re-authorization while the first session is still
     * going — authorizes against the same grant, and minting for one copy must
     * not log the other one out.
     *
     * Rotation happens against the token presented on a refresh: that one is
     * invalidated in the same step that mints its replacement, as public
     * clients require. Everything else in the set is left alone, and revoking
     * the grant still takes all of them at once.
     *
     * Null when the grant is gone — revoked while the exchange was in flight —
     * so the caller fails the request rather than answering with a hollow
     * token the client would store and never be able to redeem.
     *
     * @param  string|null  $replaces  Refresh token being exchanged, if any.
     */
    public static function issueRefreshToken(int $userId, string $grantId, ?string $replaces = null): ?string
    {
        $grant = self::get($userId, $grantId);
        if ($grant === null) {
            return null;
        }

        $token = 'gmrt_'.self::secret();
        $hash = self::hash($token);

        add_option(
            self::REFRESH_PREFIX.$hash,
            ['user' => $userId, 'grant' => $grantId, 'exp' => time() + self::REFRESH_TTL],
            '',
            false
        );

        $spent = $replaces === null ? null : self::hash($replaces);
        $live = [];
        foreach (self::refreshHashes($grant) as $existing) {
            if ($existing === $spent) {
                // Kept as a tombstone rather than deleted: a rotated token
                // turning up again means either a client bug or a copy of it
                // in someone else's hands, and the two are indistinguishable
                // from here — so the reuse has to be detectable.
                update_option(
                    self::REFRESH_PREFIX.$existing,
                    ['user' => $userId, 'grant' => $grantId, 'exp' => time() + self::REFRESH_TTL, 'spent' => true],
                    false
                );

                continue;
            }

            if (get_option(self::REFRESH_PREFIX.$existing) === false) {
                continue;
            }

            $live[] = $existing;
        }

        $live[] = $hash;
        $grant['refresh'] = $live;

        // A revoke that landed while this was in flight wins: drop the token
        // just minted rather than putting the grant back.
        if (! self::update($userId, $grantId, $grant)) {
            delete_option(self::REFRESH_PREFIX.$hash);

            return null;
        }

        return $token;
    }

    /**
     * End the whole connection because one of its refresh tokens was
     * presented a second time.
     *
     * Rotation means a spent token should never be seen again. When one is,
     * either the client is broken or somebody else has a copy — and there is
     * no way to tell which from here, so the safe reading is that the token
     * leaked (RFC 9700 §4.14.2).
     */
    public static function revokeOnReuse(string $token): bool
    {
        $payload = get_option(self::REFRESH_PREFIX.self::hash($token));
        if (! is_array($payload) || empty($payload['spent'])) {
            return false;
        }

        self::revoke((int) $payload['user'], (string) $payload['grant']);

        return true;
    }

    /**
     * Hashes of the refresh tokens a grant holds.
     *
     * @param  array<string, mixed>  $grant
     * @return string[]
     */
    private static function refreshHashes(array $grant): array
    {
        $refresh = $grant['refresh'] ?? null;

        return array_values(array_filter((array) $refresh, 'is_string'));
    }

    /**
     * @return array{user: int, grant_id: string, grant: array<string, mixed>}|null
     */
    public static function readRefreshToken(string $token): ?array
    {
        $payload = get_option(self::REFRESH_PREFIX.self::hash($token));
        if (! is_array($payload) || $payload['exp'] < time() || ! empty($payload['spent'])) {
            return null;
        }

        $grant = self::get((int) $payload['user'], (string) $payload['grant']);
        if ($grant === null || ! self::mayConnect((int) $payload['user'])) {
            return null;
        }

        return ['user' => (int) $payload['user'], 'grant_id' => (string) $payload['grant'], 'grant' => $grant];
    }

    /**
     * Drop refresh tokens that outlived their expiry, and the grants they were
     * the last live part of. Access tokens and codes are transients and clean
     * themselves up.
     */
    public static function purgeExpired(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(self::REFRESH_PREFIX).'%'
            )
        );

        foreach ($rows as $row) {
            $payload = maybe_unserialize($row->option_value);
            if (! is_array($payload) || ($payload['exp'] ?? 0) < time()) {
                delete_option($row->option_name);
            }
        }

        // A grant whose refresh token is gone and which nothing has used for
        // longer than one could have lasted is a dead connection. Left alone
        // it stays on the connections screen for ever, indistinguishable from
        // a live one.
        foreach (self::users() as $user) {
            foreach (self::all($user->ID) as $grantId => $grant) {
                if (self::isLive($grant)) {
                    continue;
                }

                self::revoke($user->ID, (string) $grantId);
            }
        }
    }

    /**
     * Could this grant still mint a token?
     *
     * @param  array<string, mixed>  $grant
     */
    public static function isLive(array $grant): bool
    {
        foreach (self::refreshHashes($grant) as $hash) {
            if (get_option(self::REFRESH_PREFIX.$hash) !== false) {
                return true;
            }
        }

        return ($grant['last_used'] ?? 0) > time() - self::REFRESH_TTL;
    }

    private static function touch(int $userId, string $grantId): void
    {
        $grant = self::get($userId, $grantId);
        if ($grant === null) {
            return;
        }

        $grant['last_used'] = time();
        self::update($userId, $grantId, $grant);
    }

    private static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
