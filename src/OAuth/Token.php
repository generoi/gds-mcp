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

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw Server::error('invalid_request', 'POST required.', 405);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- OAuth
        // token requests are authenticated by the code and PKCE verifier, not
        // by a WordPress nonce.
        $grantType = isset($_POST['grant_type']) ? sanitize_text_field(wp_unslash($_POST['grant_type'])) : '';

        throw match ($grantType) {
            'authorization_code' => self::exchangeCode(),
            'refresh_token' => self::refresh(),
            default => Server::error('unsupported_grant_type', 'Unsupported grant_type.'),
        };
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    private static function exchangeCode(): ResponseException
    {
        $code = self::param('code');
        $verifier = self::param('code_verifier');
        $clientId = self::param('client_id');
        $redirectUri = self::param('redirect_uri');

        $payload = Grants::consumeCode($code);
        if ($payload === null) {
            return Server::error('invalid_grant', 'The authorization code is invalid or has expired.');
        }

        // The code is bound to the client and callback it was issued for, and
        // to the PKCE challenge only the original requester can answer.
        if (! hash_equals($payload['client_id'], $clientId)
            || ($redirectUri !== '' && ! hash_equals($payload['redirect_uri'], $redirectUri))
            || ! self::verifyPkce($verifier, $payload['code_challenge'])) {
            return Server::error('invalid_grant', 'The authorization code does not match this request.');
        }

        return self::tokens((int) $payload['user'], (string) $payload['grant']);
    }

    private static function refresh(): ResponseException
    {
        $payload = Grants::readRefreshToken(self::param('refresh_token'));
        if ($payload === null) {
            // invalid_grant specifically: on anything else the client keeps
            // retrying a token it can never redeem instead of re-authorizing.
            return Server::error('invalid_grant', 'The refresh token is invalid or has expired.');
        }

        $clientId = self::param('client_id');
        if ($clientId !== '' && ! hash_equals($payload['grant']['client_id'], $clientId)) {
            return Server::error('invalid_grant', 'The refresh token belongs to another client.');
        }

        return self::tokens($payload['user'], $payload['grant_id']);
    }

    /**
     * Mint a fresh pair. The refresh token rotates on every use, as required
     * for public clients.
     */
    private static function tokens(int $userId, string $grantId): ResponseException
    {
        $accessToken = Grants::issueAccessToken($userId, $grantId);
        $refreshToken = Grants::issueRefreshToken($userId, $grantId);

        return ResponseException::json([
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

        $token = self::param('token');

        if ($token !== '') {
            $refresh = Grants::readRefreshToken($token);
            if ($refresh !== null) {
                Grants::revoke($refresh['user'], $refresh['grant_id']);
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
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }
}
