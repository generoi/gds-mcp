<?php

namespace GeneroWP\MCP\OAuth;

use WP\MCP\Core\McpAdapter;

/**
 * Discovery documents, and the arithmetic that decides which MCP endpoint a
 * URL or route belongs to. A client that cannot read these documents never
 * starts the flow, so both are public and served uncached.
 */
final class Metadata
{
    public const SCOPES = ['mcp', 'offline_access'];

    /**
     * Every MCP endpoint this site exposes, as absolute URLs.
     *
     * These are the protected resources: a token is bound to one of them and
     * is rejected anywhere else.
     *
     * @return string[]
     */
    public static function resources(): array
    {
        $resources = [];

        if (class_exists(McpAdapter::class)) {
            $adapter = McpAdapter::instance();

            // Outside REST and WP-CLI the adapter defers server registration to
            // rest_api_init, which never fires for the OAuth endpoints — those
            // are ordinary front-end requests. init() guards against repeats.
            if ($adapter->get_servers() === []) {
                $adapter->init();
            }

            foreach ($adapter->get_servers() as $server) {
                $resources[] = untrailingslashit(
                    rest_url($server->get_server_route_namespace().'/'.$server->get_server_route())
                );
            }
        }

        /**
         * Endpoints a token may be issued for. Sites fronting the MCP endpoint
         * with a different URL add it here, or the client's `resource` will
         * not match anything this site admits to serving.
         *
         * @param  string[]  $resources
         */
        $resources = (array) apply_filters('gds-mcp/oauth_resources', $resources);

        // Normalised here rather than at each comparison: a filtered value with
        // a trailing slash would otherwise match nothing a client sends, and
        // the client would be told `invalid_target` with no way to see why.
        return array_values(array_unique(array_map('untrailingslashit', $resources)));
    }

    /**
     * Resolve a resource URL the client asked about, or the site's default.
     */
    public static function resolveResource(?string $resource): ?string
    {
        $resources = self::resources();
        if ($resources === []) {
            return null;
        }

        if ($resource === null || $resource === '') {
            return $resources[0];
        }

        $resource = untrailingslashit($resource);

        return in_array($resource, $resources, true) ? $resource : null;
    }

    /**
     * The MCP endpoint a REST route belongs to, or null for any other route.
     *
     * Resolving by route rather than by rebuilding the URL is what makes this
     * work for a site that advertises a fronting URL through the
     * `gds-mcp/oauth_resources` filter, where the route is all the two have in
     * common.
     */
    public static function resourceForRoute(string $route): ?string
    {
        $route = untrailingslashit($route);
        if ($route === '') {
            return null;
        }

        foreach (self::resources() as $resource) {
            if (self::routeOf($resource) === $route) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Path the site's REST API is served from, e.g. `/wp-json` or
     * `/blog/index.php/wp-json`. Null on a site with plain permalinks, which
     * has no REST path at all — only the `rest_route` query variable.
     */
    public static function restBase(): ?string
    {
        // `determine_current_user` can fire before the rewrite rules are set
        // up, and rest_url() reads them — so make the same decision it does,
        // from the option it is derived from.
        if (! isset($GLOBALS['wp_rewrite'])) {
            $structure = (string) get_option('permalink_structure');
            if ($structure === '') {
                return null;
            }

            return Server::homePath()
                .(str_starts_with($structure, '/index.php') ? '/index.php' : '')
                .'/'.rest_get_url_prefix();
        }

        $url = rest_url('/');

        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if (! empty($query['rest_route'])) {
            return null;
        }

        // An empty base is the same as none: it would otherwise be a prefix of
        // every path on the site, and so make every path look like the REST
        // API. A filtered-away `rest_url_prefix` gets here.
        $base = untrailingslashit((string) wp_parse_url($url, PHP_URL_PATH));

        // A base that adds nothing to the site's own path is not a base: it
        // would be a prefix of every path on the site, and so make every path
        // look like the REST API.
        return $base === '' || $base === Server::homePath() ? null : $base;
    }

    /**
     * The REST route a request path resolves to, or null when the path is not
     * under the REST base at all.
     *
     * The base is also matched with the site's own directory removed, because
     * WordPress strips the home path only when the request actually carries it
     * (`WP::parse_request()`): a proxy publishing the site under a path can
     * hand the origin a URI without it, and WordPress still routes that to
     * REST.
     */
    public static function routeForPath(string $path): ?string
    {
        $base = self::restBase();
        if ($base === null || $base === '') {
            return null;
        }

        $home = Server::homePath();
        $bases = [$base];
        if ($home !== '' && str_starts_with($base, $home)) {
            $bases[] = substr($base, strlen($home));
        }

        foreach (array_unique($bases) as $candidate) {
            if ($candidate !== '' && str_starts_with($path, $candidate.'/')) {
                return untrailingslashit(substr($path, strlen($candidate)));
            }
        }

        return null;
    }

    public static function pathOf(string $resource): string
    {
        return untrailingslashit((string) wp_parse_url($resource, PHP_URL_PATH));
    }

    /**
     * The REST route a resource URL resolves to, e.g. `/mcp/my-server`.
     *
     * WordPress dispatches REST requests by the `rest_route` query variable,
     * not by URL path, so this — and not the path — is what a token's audience
     * has to be checked against. Null when the URL is not a REST URL.
     */
    public static function routeOf(string $resource): ?string
    {
        // Plain-permalink sites address REST as /index.php?rest_route=/…
        parse_str((string) wp_parse_url($resource, PHP_URL_QUERY), $query);
        if (! empty($query['rest_route']) && is_string($query['rest_route'])) {
            return untrailingslashit($query['rest_route']);
        }

        // …every other site carries it in the path, under a base that also
        // includes the site's own directory and any index.php in the way.
        return self::routeForPath(self::pathOf($resource));
    }

    /**
     * URL of the protected resource metadata document for one resource.
     *
     * Claude is told about this document in the WWW-Authenticate challenge, so
     * it does not have to guess the location.
     */
    public static function protectedResourceUrl(string $resource): string
    {
        // RFC 9728 §3.1 puts the document at the origin root with the
        // resource's path appended — not under the site's own path, which for
        // a subdirectory install would repeat that prefix and point at a URL
        // WordPress never sees.
        //
        // A site addressing REST by query variable has no path to tell its
        // servers apart — every one of them is `/index.php`, which core spells
        // out rather than leaving empty — so the route stands in for it.
        // Without that, all but the first server would advertise a document
        // naming somebody else's endpoint.
        parse_str((string) wp_parse_url($resource, PHP_URL_QUERY), $query);
        $suffix = empty($query['rest_route'])
            ? self::pathOf($resource)
            : (string) self::routeOf($resource);

        return Server::origin().'/.well-known/oauth-protected-resource'.$suffix;
    }

    /**
     * @param  string  $suffix  Path following /.well-known/oauth-protected-resource,
     *                          naming the resource being asked about.
     */
    public static function serveProtectedResource(string $suffix): void
    {
        Server::handlePreflight('GET');

        $resource = self::resolveResource($suffix === '' ? null : Server::origin().$suffix);

        // The suffix may be a route rather than a path — see
        // protectedResourceUrl() for the sites where it is.
        if ($resource === null && $suffix !== '') {
            foreach (self::resources() as $candidate) {
                if (self::routeOf($candidate) === untrailingslashit($suffix)) {
                    $resource = $candidate;

                    break;
                }
            }
        }

        if ($resource === null) {
            throw Server::error('not_found', 'Unknown protected resource.', 404);
        }

        throw ResponseException::json([
            'resource' => $resource,
            'authorization_servers' => [Server::issuer()],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => get_bloginfo('name').' MCP',
            'resource_documentation' => 'https://github.com/generoi/gds-mcp',
        ]);
    }

    public static function serveAuthorizationServer(): void
    {
        Server::handlePreflight('GET');

        throw ResponseException::json([
            'issuer' => Server::issuer(),
            'authorization_endpoint' => Server::endpoint('authorize'),
            'token_endpoint' => Server::endpoint('token'),
            'registration_endpoint' => Server::endpoint('register'),
            'revocation_endpoint' => Server::endpoint('revoke'),
            'scopes_supported' => self::SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // Clients are public: they hold no secret, PKCE binds the code to
            // the client instead.
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'service_documentation' => 'https://github.com/generoi/gds-mcp',
        ]);
    }
}
