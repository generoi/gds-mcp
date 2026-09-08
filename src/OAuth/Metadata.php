<?php

namespace GeneroWP\MCP\OAuth;

use WP\MCP\Core\McpAdapter;

/**
 * Discovery documents. A client that cannot read these never starts the flow,
 * so both are public and cheap.
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
        return array_values(array_unique((array) apply_filters('gds-mcp/oauth_resources', $resources)));
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
     * Is this REST route one of the MCP endpoints?
     */
    public static function isResourceRoute(string $route): bool
    {
        $route = untrailingslashit($route);
        if ($route === '') {
            return false;
        }

        foreach (self::resources() as $resource) {
            if (self::routeOf($resource) === $route) {
                return true;
            }
        }

        return false;
    }

    /**
     * Path the site's REST API is served from, e.g. `/wp-json` or
     * `/blog/index.php/wp-json`. Null on a site with plain permalinks, which
     * has no REST path at all — only the `rest_route` query variable.
     */
    public static function restBase(): ?string
    {
        // `determine_current_user` can fire before the rewrite rules are set
        // up, and rest_url() reads them. The conventional layout is the right
        // answer then: a site that does not use it addresses REST by query
        // variable, which is matched separately.
        if (! isset($GLOBALS['wp_rewrite'])) {
            return Server::homePath().'/'.rest_get_url_prefix();
        }

        $url = rest_url('/');

        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if (! empty($query['rest_route'])) {
            return null;
        }

        return untrailingslashit((string) wp_parse_url($url, PHP_URL_PATH));
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
     * has to be checked against. Returns '' when the URL is not a REST URL,
     * which callers must treat as "matches nothing".
     */
    public static function routeOf(string $resource): string
    {
        // Plain-permalink sites address REST as /index.php?rest_route=/…
        parse_str((string) wp_parse_url($resource, PHP_URL_QUERY), $query);
        if (! empty($query['rest_route']) && is_string($query['rest_route'])) {
            return untrailingslashit($query['rest_route']);
        }

        // …every other site carries it in the path, under a base that also
        // includes the site's own directory and any index.php in the way.
        $base = self::restBase();
        $path = self::pathOf($resource);

        return $base !== null && str_starts_with($path, $base.'/')
            ? untrailingslashit(substr($path, strlen($base)))
            : '';
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
        return Server::origin().'/.well-known/oauth-protected-resource'.self::pathOf($resource);
    }

    /**
     * @param  string  $suffix  Path following /.well-known/oauth-protected-resource,
     *                          naming the resource being asked about.
     */
    public static function serveProtectedResource(string $suffix): void
    {
        Server::handlePreflight('GET');

        $resource = self::resolveResource($suffix === '' ? null : Server::origin().$suffix);

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
