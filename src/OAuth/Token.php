<?php

namespace GeneroWP\MCP\OAuth;

/**
 * Token and revocation endpoints.
 *
 * Bodies are form-urlencoded (RFC 6749 §4.1.3) for both the initial exchange
 * and refreshes.
 */
final class Token
{
    public static function handle(): void
    {
        Server::handlePreflight('POST');
        Server::requireTls();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw Server::error('invalid_request', 'POST required.', 405);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- OAuth
        // token requests are authenticated by the code and PKCE verifier, not
        // by a WordPress nonce.
        $grantType = isset($_POST['grant_type']) ? sanitize_text_field(wp_unslash($_POST['grant_type'])) : '';

        match ($grantType) {
            'authorization_code' => self::exchangeCode(),
            'refresh_token' => self::refresh(),
            default => throw Server::error('unsupported_grant_type', 'Unsupported grant_type.'),
        };
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * @return never
     */
    private static function exchangeCode(): void
    {
        $code = self::param('code');
        $verifier = self::param('code_verifier');
        $clientId = self::param('client_id');
        $redirectUri = self::param('redirect_uri');

        $payload = Grants::consumeCode($code);
        if ($payload === null) {
            throw Server::error('invalid_grant', 'The authorization code is invalid or has expired.');
        }

        // The code is bound to the client and callback it was issued for, and
        // to the PKCE challenge only the original requester can answer.
        if (! hash_equals($payload['client_id'], $clientId)
            || ($redirectUri !== '' && ! hash_equals($payload['redirect_uri'], $redirectUri))
            || ! self::verifyPkce($verifier, $payload['code_challenge'])) {
            throw Server::error('invalid_grant', 'The authorization code does not match this request.');
        }

        // RFC 8707 §2.2: a token request may narrow the audience, never widen
        // it beyond what the code was issued for.
        $resource = self::param('resource');
        if ($resource !== '' && ! hash_equals((string) $payload['resource'], untrailingslashit($resource))) {
            throw Server::error('invalid_target', 'The requested resource is not the one this code was issued for.');
        }

        self::tokens((int) $payload['user'], (string) $payload['grant']);
    }

    /**
     * @return never
     */
    private static function refresh(): void
    {
        $presented = self::param('refresh_token');
        $payload = Grants::readRefreshToken($presented);
        if ($payload === null) {
            // A token that was already exchanged turning up again ends the
            // whole connection: rotation means it should never be seen twice,
            // so the likeliest reading is that a copy of it leaked.
            Grants::revokeOnReuse($presented);

            // invalid_grant specifically: on anything else the client keeps
            // retrying a token it can never redeem instead of re-authorizing.
            throw Server::error('invalid_grant', 'The refresh token is invalid or has expired.');
        }

        // RFC 6749 §6: the client identifies itself on a refresh. A public
        // client has no secret, so this is all that ties a refresh token to
        // the client it was issued to.
        $clientId = self::param('client_id');
        if ($clientId === '') {
            throw Server::error('invalid_client', 'A client_id is required to refresh.', 401);
        }

        if (! hash_equals($payload['grant']['client_id'], $clientId)) {
            throw Server::error('invalid_grant', 'The refresh token belongs to another client.');
        }

        // A connection that never re-authorizes is still in use, and pruning
        // measures the age of the connection.
        Clients::touch($clientId);

        self::tokens($payload['user'], $payload['grant_id'], self::param('refresh_token'));
    }

    /**
     * Mint a fresh pair. The refresh token rotates on every use, as required
     * for public clients.
     *
     * @return never
     */
    private static function tokens(int $userId, string $grantId, ?string $replaces = null): void
    {
        $refreshToken = Grants::issueRefreshToken($userId, $grantId, $replaces);
        if ($refreshToken === null) {
            throw Server::error('invalid_grant', 'This authorization has been revoked.');
        }

        $accessToken = Grants::issueAccessToken($userId, $grantId);

        throw ResponseException::json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => Grants::ACCESS_TTL,
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', Metadata::SCOPES),
        ]);
    }

    /**
     * RFC 7009: revoking is idempotent and always answers 200, so a client
     * cannot use it to probe which tokens exist.
     */
    public static function handleRevocation(): void
    {
        Server::handlePreflight('POST');
        Server::requireTls();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw Server::error('invalid_request', 'POST required.', 405);
        }

        $token = self::param('token');

        // Either token type may be presented, and `token_type_hint` is only a
        // hint — so try both. Revoking one ends the whole authorization, which
        // is what a client disconnecting means by it.
        if ($token !== '') {
            $grant = Grants::readRefreshToken($token) ?? Grants::readAccessToken($token);
            if ($grant !== null) {
                Grants::revoke($grant['user'], $grant['grant_id']);
            }
        }

        throw ResponseException::json([]);
    }

    private static function verifyPkce(string $verifier, string $challenge): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }

    private static function param(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see handle().
        return Server::param($_POST, $key);
    }
}
