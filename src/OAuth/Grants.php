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
 */
final class Grants
{
    /**
     * User meta holding that user's grants, keyed by grant id.
     */
    public const META_KEY = '_gds_mcp_oauth_grants';

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
        $grantId = bin2hex(random_bytes(8));

        $grants = self::all($userId);
        $grants[$grantId] = $grant + [
            'created' => time(),
            'last_used' => time(),
            'refresh' => null,
        ];
        update_user_meta($userId, self::META_KEY, $grants);

        return $grantId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $userId, string $grantId): ?array
    {
        return self::all($userId)[$grantId] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(int $userId): array
    {
        $grants = get_user_meta($userId, self::META_KEY, true);

        return is_array($grants) ? $grants : [];
    }

    public static function revoke(int $userId, string $grantId): bool
    {
        $grants = self::all($userId);
        if (! isset($grants[$grantId])) {
            return false;
        }

        if (! empty($grants[$grantId]['refresh'])) {
            delete_option(self::REFRESH_PREFIX.$grants[$grantId]['refresh']);
        }

        unset($grants[$grantId]);
        update_user_meta($userId, self::META_KEY, $grants);

        return true;
    }

    /**
     * Users holding at least one grant, for the admin screen.
     *
     * @return \WP_User[]
     */
    public static function users(): array
    {
        return get_users([
            'meta_key' => self::META_KEY,
            'meta_compare' => 'EXISTS',
        ]);
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

    // ── Refresh tokens ───────────────────────────────────────────

    /**
     * Issue a refresh token, replacing any the grant already had.
     *
     * Public clients must rotate refresh tokens, so the previous one is
     * invalidated in the same step that mints its replacement. Null when the
     * grant is gone — revoked while the exchange was in flight — so the caller
     * fails the request rather than answering with a hollow token the client
     * would store and never be able to redeem.
     */
    public static function issueRefreshToken(int $userId, string $grantId): ?string
    {
        $grants = self::all($userId);
        if (! isset($grants[$grantId])) {
            return null;
        }

        if (! empty($grants[$grantId]['refresh'])) {
            delete_option(self::REFRESH_PREFIX.$grants[$grantId]['refresh']);
        }

        $token = 'gmrt_'.self::secret();
        $hash = self::hash($token);

        add_option(
            self::REFRESH_PREFIX.$hash,
            ['user' => $userId, 'grant' => $grantId, 'exp' => time() + self::REFRESH_TTL],
            '',
            false
        );

        $grants[$grantId]['refresh'] = $hash;
        update_user_meta($userId, self::META_KEY, $grants);

        return $token;
    }

    /**
     * @return array{user: int, grant_id: string, grant: array<string, mixed>}|null
     */
    public static function readRefreshToken(string $token): ?array
    {
        $payload = get_option(self::REFRESH_PREFIX.self::hash($token));
        if (! is_array($payload) || $payload['exp'] < time()) {
            return null;
        }

        $grant = self::get((int) $payload['user'], (string) $payload['grant']);
        if ($grant === null) {
            return null;
        }

        return ['user' => (int) $payload['user'], 'grant_id' => (string) $payload['grant'], 'grant' => $grant];
    }

    /**
     * Drop refresh tokens that outlived their expiry. Access tokens and codes
     * are transients and clean themselves up.
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
    }

    private static function touch(int $userId, string $grantId): void
    {
        $grants = self::all($userId);
        if (! isset($grants[$grantId])) {
            return;
        }

        $grants[$grantId]['last_used'] = time();
        update_user_meta($userId, self::META_KEY, $grants);
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
