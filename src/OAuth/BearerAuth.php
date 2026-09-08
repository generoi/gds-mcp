<?php

namespace GeneroWP\MCP\OAuth;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

/**
 * Turns a bearer token into the WordPress user it was issued for, and tells
 * unauthenticated clients where to go and get one.
 */
final class BearerAuth
{
    /**
     * REST route the bearer token on this request is entitled to reach,
     * e.g. `/mcp/my-server`.
     */
    private static ?string $audience = null;

    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'authenticate']);
        // Runs early and passes a non-error result through instead of
        // short-circuiting on it, so a filter answering `true` — "some auth
        // method succeeded" — cannot take the audience check out of the chain.
        add_filter('rest_authentication_errors', [self::class, 'enforceAudience'], 1);
        add_filter('rest_post_dispatch', [self::class, 'challenge'], 10, 3);

        // A browser-resident client reads the challenge off a cross-origin
        // 401, which it cannot do unless the header is exposed.
        add_filter('rest_exposed_cors_headers', function (array $headers): array {
            $headers[] = 'WWW-Authenticate';

            return $headers;
        });
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
        $audience = Metadata::routeOf($payload['grant']['resource']);
        $route = self::requestedRoute();

        if ($audience === null || $route === null || ! hash_equals($audience, $route)) {
            return $user;
        }

        // Pinned to the site's own home URL, path included: on a subdirectory
        // network every site shares an origin, and the route is identical, so
        // the path is the only thing telling two sites' grants apart.
        if (! str_starts_with($payload['grant']['resource'], untrailingslashit(home_url()).'/')) {
            return $user;
        }

        self::$audience = $audience;

        return $payload['user'];
    }

    /**
     * Where a bearer token authenticated this request, re-check its audience
     * against the route WordPress actually parsed.
     *
     * `authenticate()` has to work the route out for itself, because the
     * current user can be resolved before the request is parsed; this filter
     * has the parsed route in hand. A request authenticated any other way
     * passes straight through.
     *
     * @param  mixed  $errors  Authentication result so far.
     * @return mixed
     */
    public static function enforceAudience($errors)
    {
        if (self::$audience === null || is_wp_error($errors)) {
            return $errors;
        }

        // Checked here rather than while the user was being resolved, where a
        // capability check can re-enter that resolution through `user_has_cap`.
        if (! Grants::mayConnect(get_current_user_id())) {
            return new WP_Error(
                'rest_connection_not_permitted',
                __('This account is no longer allowed to connect an MCP client.', 'gds-mcp'),
                ['status' => 401]
            );
        }

        $route = isset($GLOBALS['wp']->query_vars['rest_route'])
            ? untrailingslashit((string) $GLOBALS['wp']->query_vars['rest_route'])
            : '';

        if (hash_equals(self::$audience, $route)) {
            return $errors;
        }

        return new WP_Error(
            'rest_token_wrong_audience',
            __('This token is not valid for this endpoint.', 'gds-mcp'),
            ['status' => 401]
        );
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

        $resource = Metadata::resourceForRoute($request->get_route());
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

    /**
     * The REST route this request will be dispatched to, or null when it is
     * not a REST request at all.
     *
     * Mirrors WP::parse_request(): an explicit `rest_route` in the body or
     * query string overrides the one the permalink rewrite derives from the
     * path, so reading the path alone would let a request addressed to the MCP
     * endpoint be served by any other route.
     */
    private static function requestedRoute(): ?string
    {
        $path = Server::requestPath();
        $route = Metadata::routeForPath($path);

        // `rest_route` names a route only where WordPress reads it as one:
        // under the REST base, or at a front controller. Anywhere else —
        // admin-ajax.php, admin-post.php, wp-comments-post.php — it is an
        // ordinary parameter, and honouring it there would authenticate a
        // request no REST check ever sees, since `rest_authentication_errors`
        // fires only inside serve_request().
        if ($route === null && ! self::isFrontController($path)) {
            return null;
        }

        // phpcs:disable WordPress.Security.NonceVerification -- Reads the route
        // WordPress itself will dispatch; changes nothing.
        foreach ([$_POST, $_GET] as $source) {
            if (isset($source['rest_route']) && is_string($source['rest_route'])) {
                return untrailingslashit(Server::param($source, 'rest_route'));
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification

        return $route;
    }

    /**
     * Is this path one of the scripts that boots WordPress and lets it route
     * the request — as opposed to one that serves a request of its own?
     */
    private static function isFrontController(string $path): bool
    {
        $home = Server::homePath();
        $site = untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH));

        // The home path is included with and without the site's own directory:
        // WordPress strips it only when the request carries it, and core may
        // live somewhere else again, as it does under Bedrock.
        $fronts = [
            $home === '' ? '/' : $home,
            $home.'/index.php',
            '/',
            '/index.php',
            $site.'/index.php',
        ];

        return in_array($path, array_unique($fronts), true);
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
