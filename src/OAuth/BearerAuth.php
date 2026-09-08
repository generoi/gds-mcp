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
        // Ahead of core's own authentication filters (90, 100), so a plugin
        // that answers earlier cannot take this check out of the chain.
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

        if ($audience === '' || $route === null || ! hash_equals($audience, $route)) {
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
     * Re-check the audience against the route WordPress actually dispatches.
     *
     * `authenticate()` has to work the route out for itself, because the
     * current user can be resolved before the request is parsed. This runs
     * once per REST request with the parsed route in hand, so it is the
     * authoritative check; the two disagreeing means the route was rewritten
     * in between.
     *
     * @param  mixed  $errors  Authentication result so far.
     * @return mixed
     */
    public static function enforceAudience($errors)
    {
        if (self::$audience === null || is_wp_error($errors)) {
            return $errors;
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

        $route = untrailingslashit($request->get_route());
        if (! Metadata::isResourceRoute($route)) {
            return $response;
        }

        $resource = Metadata::resolveResource(rest_url($route));
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
        $home = Server::homePath();
        $base = Metadata::restBase();
        $path = Server::requestPath();
        $pretty = $base !== null && str_starts_with($path, $base.'/');

        // `rest_route` names a route only where WordPress reads it as one: at
        // the site's front controller, which is how a plain-permalink site
        // addresses REST. Anywhere else — admin-ajax.php, admin-post.php,
        // wp-comments-post.php — it is an ordinary parameter, and honouring it
        // there would authenticate a request no REST check ever sees, since
        // `rest_authentication_errors` fires only inside serve_request().
        $index = in_array($path, [$home === '' ? '/' : $home, $home.'/index.php'], true);

        if (! $pretty && ! $index) {
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

        return $pretty ? untrailingslashit(substr($path, strlen((string) $base))) : null;
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
