<?php

namespace GeneroWP\MCP\OAuth;

use WP_HTTP_Response;
use WP_REST_Request;

/**
 * Turns a bearer token into the WordPress user it was issued for, and tells
 * unauthenticated clients where to go and get one.
 */
final class BearerAuth
{
    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'authenticate']);
        add_filter('rest_post_dispatch', [self::class, 'challenge'], 10, 3);
    }

    /**
     * @param  int|false  $user  User id resolved so far.
     * @return int|false
     */
    public static function authenticate($user)
    {
        if ($user) {
            return $user;
        }

        $token = self::bearerToken();
        if ($token === null) {
            return $user;
        }

        $payload = Grants::readAccessToken($token);
        if ($payload === null) {
            return $user;
        }

        // A token is bound to the MCP endpoint it was issued for. Without this
        // check a leaked token would be a site-wide credential, usable against
        // every other REST route the account can reach.
        if (Metadata::pathOf($payload['grant']['resource']) !== Server::requestPath()) {
            return $user;
        }

        return $payload['user'];
    }

    /**
     * Answer an unauthenticated MCP request with a 401 naming the metadata
     * document, which is what starts the OAuth flow in the client.
     *
     * The transport's own permission check returns 403, and Claude only reads
     * the challenge from a 401 — so the status is normalised here too.
     */
    public static function challenge(mixed $response, mixed $server, mixed $request): mixed
    {
        if (! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request) {
            return $response;
        }

        if (! in_array($response->get_status(), [401, 403], true) || is_user_logged_in()) {
            return $response;
        }

        $path = untrailingslashit((string) wp_parse_url(rest_url($request->get_route()), PHP_URL_PATH));
        if (! Metadata::isResourcePath($path)) {
            return $response;
        }

        $resource = Metadata::resolveResource(Server::origin().$path);
        if ($resource === null) {
            return $response;
        }

        $response->set_status(401);
        $response->header(
            'WWW-Authenticate',
            sprintf(
                'Bearer resource_metadata="%s", scope="%s"',
                Metadata::protectedResourceUrl($resource),
                implode(' ', Metadata::SCOPES)
            )
        );

        return $response;
    }

    private static function bearerToken(): ?string
    {
        $header = '';
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (! empty($_SERVER[$key])) {
                $header = sanitize_text_field(wp_unslash($_SERVER[$key]));

                break;
            }
        }

        // Some Apache setups strip the header from $_SERVER entirely.
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;

                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
