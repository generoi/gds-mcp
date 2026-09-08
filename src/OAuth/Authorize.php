<?php

namespace GeneroWP\MCP\OAuth;

/**
 * Authorization endpoint: authorization code flow with PKCE (S256).
 *
 * The person authorizing is whoever is logged into WordPress in that browser,
 * which is the whole point of the module — the resulting token acts as them,
 * with their capabilities.
 */
final class Authorize
{
    private const NONCE_ACTION = 'gds-mcp-oauth-consent';

    public static function handle(): void
    {
        if (! Server::isSecure()) {
            self::fail(__('This site must be served over HTTPS to authorize MCP clients.', 'gds-mcp'));
        }

        $params = self::params();
        $client = Clients::get($params['client_id']);

        // A bad client_id or redirect_uri cannot be reported back to the
        // client — that would turn this endpoint into an open redirector — so
        // these two are rendered on-site instead (RFC 6749 §4.1.2.1).
        if ($client === null) {
            self::fail(__('Unknown MCP client. Try connecting again.', 'gds-mcp'));
        }

        $redirectUri = $params['redirect_uri'];
        if ($redirectUri === '' && count($client['redirect_uris']) === 1) {
            $redirectUri = $client['redirect_uris'][0];
        }

        if ($redirectUri === '' || ! Clients::matchRedirectUri($client, $redirectUri)) {
            self::fail(__('The client asked to be sent to an address it has not registered.', 'gds-mcp'));
        }

        // Sign in before anything that reports an error by redirecting to the
        // client: bouncing an anonymous visitor to an address that anyone can
        // self-register would make this endpoint an open redirector wearing
        // the site's own domain.
        if (! is_user_logged_in()) {
            // Rebuilt from the parameters rather than taken from the request:
            // a consent POST that arrives with an expired cookie carries them
            // in its body, where a REQUEST_URI-based round trip would lose
            // them and strand the person on "Unknown MCP client". The consent
            // itself is deliberately not carried back — the form is shown
            // again after logging in.
            throw ResponseException::redirect(wp_login_url(self::callback(
                Server::endpoint('authorize'),
                array_diff_key($params, array_flip(['consent', 'nonce']))
            )));
        }

        if ($params['response_type'] !== 'code') {
            self::reject($redirectUri, $params['state'], 'unsupported_response_type');
        }

        if ($params['code_challenge'] === '' || $params['code_challenge_method'] !== 'S256') {
            self::reject($redirectUri, $params['state'], 'invalid_request', 'PKCE with S256 is required.');
        }

        $resource = Metadata::resolveResource($params['resource']);
        if ($resource === null) {
            self::reject($redirectUri, $params['state'], 'invalid_target', 'Unknown MCP resource.');
        }

        if (! current_user_can(Server::capability())) {
            self::fail(
                sprintf(
                    /* translators: %s: WordPress user login name. */
                    __('Your account (%s) is not allowed to connect an MCP client to this site.', 'gds-mcp'),
                    wp_get_current_user()->user_login
                ),
                $redirectUri,
                $params['state']
            );
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            self::consent($client, $redirectUri, $params, $resource);
        }

        self::form($client, $redirectUri, $params, $resource);
    }

    /**
     * Handle the submitted consent form.
     *
     * @param  array<string, mixed>  $client
     * @param  array<string, string>  $params
     * @return never
     */
    private static function consent(
        array $client,
        string $redirectUri,
        array $params,
        string $resource
    ): void {
        if (! wp_verify_nonce($params['nonce'], self::NONCE_ACTION)) {
            self::fail(__('This authorization form expired. Try connecting again.', 'gds-mcp'));
        }

        if ($params['consent'] !== 'allow') {
            self::reject($redirectUri, $params['state'], 'access_denied');
        }

        Clients::touch($client['client_id']);

        $userId = get_current_user_id();
        $grantId = Grants::create($userId, [
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'resource' => $resource,
            'scopes' => Metadata::SCOPES,
        ]);

        $code = Grants::issueCode([
            'user' => $userId,
            'grant' => $grantId,
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $params['code_challenge'],
            'resource' => $resource,
        ]);

        throw ResponseException::redirect(
            self::callback($redirectUri, ['code' => $code, 'state' => $params['state']])
        );
    }

    /**
     * Build a callback URL.
     *
     * `add_query_arg()` is not usable here: it defers to `build_query()`,
     * which appends values unencoded, so a `state` carrying an `&` or a `#` —
     * both legal in an opaque value — would arrive at the client truncated or
     * split into extra parameters.
     *
     * @param  array<string, string>  $params
     */
    private static function callback(string $uri, array $params): string
    {
        // Only absent values are dropped: "0" is a legal opaque state, and a
        // client that gets it back missing reads that as a CSRF failure.
        $params = array_filter($params, static fn (string $value): bool => $value !== '');
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($query === '') {
            return $uri;
        }

        return $uri.(str_contains($uri, '?') ? '&' : '?').$query;
    }

    /**
     * @return array<string, string>
     */
    private static function params(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification -- OAuth parameters
        // arrive from the client, not from a WordPress form; the consent POST
        // carries its own nonce, checked in consent().
        //
        // Read from the actual method's array rather than $_REQUEST, whose
        // contents depend on php.ini's request_order — a configuration that
        // includes cookies would let a cookie on a sibling subdomain override
        // the client and callback the visitor is being asked to approve.
        $source = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
        $get = fn (string $key): string => Server::param($source, $key);

        return [
            'response_type' => $get('response_type'),
            'client_id' => $get('client_id'),
            'redirect_uri' => esc_url_raw($get('redirect_uri')),
            'state' => $get('state'),
            'scope' => $get('scope'),
            'resource' => $get('resource'),
            'code_challenge' => $get('code_challenge'),
            'code_challenge_method' => $get('code_challenge_method'),
            'consent' => $get('consent'),
            'nonce' => $get('_wpnonce'),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Report an error back to the client (RFC 6749 §4.1.2.1).
     *
     * @return never
     */
    private static function reject(
        string $redirectUri,
        string $state,
        string $error,
        string $description = ''
    ): void {
        throw ResponseException::redirect(self::callback($redirectUri, [
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ]));
    }

    /**
     * Render a dead end the person can read, for the cases that must not be
     * redirected anywhere.
     *
     * @return never
     */
    private static function fail(
        string $message,
        string $redirectUri = '',
        string $state = ''
    ): void {
        $back = $redirectUri === ''
            ? ''
            : self::callback($redirectUri, ['error' => 'access_denied', 'state' => $state]);

        throw self::page(
            __('Authorization failed', 'gds-mcp'),
            '<p>'.esc_html($message).'</p>'
            .($back !== ''
                ? '<p><a href="'.esc_url($back).'">'.esc_html__('Return to the application', 'gds-mcp').'</a></p>'
                : '')
            .'<p><a href="'.esc_url(wp_logout_url(Server::currentUrl())).'">'
            .esc_html__('Sign in as a different user', 'gds-mcp').'</a></p>',
            403
        );
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, string>  $params
     * @return never
     */
    private static function form(
        array $client,
        string $redirectUri,
        array $params,
        string $resource
    ): void {
        $user = wp_get_current_user();
        $hidden = '';
        foreach ([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $params['state'],
            'scope' => $params['scope'],
            'resource' => $resource,
            'code_challenge' => $params['code_challenge'],
            'code_challenge_method' => 'S256',
        ] as $name => $value) {
            $hidden .= '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
        }

        // The name is whatever the application called itself when it
        // registered — anyone can register — so the page says so, and shows
        // the callback in full rather than just its host. Those two are all a
        // person has to tell a real client from one that picked a
        // reassuring name.
        $body = '<p class="lead">'
            .sprintf(
                /* translators: 1: MCP client name, 2: site name. */
                esc_html__('An application calling itself %1$s wants to use %2$s as you.', 'gds-mcp'),
                '<strong>'.esc_html($client['client_name']).'</strong>',
                '<strong>'.esc_html(get_bloginfo('name')).'</strong>'
            )
            .'</p>'
            .'<dl>'
            .'<dt>'.esc_html__('Signed in as', 'gds-mcp').'</dt><dd>'.esc_html($user->user_login).'</dd>'
            .'<dt>'.esc_html__('It can do', 'gds-mcp').'</dt><dd>'
            .esc_html__('Anything your account can do, until you revoke it under Users → MCP connections.', 'gds-mcp')
            .'</dd>'
            .'<dt>'.esc_html__('Sends you back to', 'gds-mcp').'</dt>'
            .'<dd><span class="uri">'.esc_html($redirectUri).'</span></dd>'
            .'</dl>'
            .'<form method="post" action="'.esc_url(Server::endpoint('authorize')).'">'
            .$hidden
            .wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false)
            .'<button type="submit" name="consent" value="allow" class="primary">'
            .esc_html__('Allow access', 'gds-mcp').'</button> '
            .'<button type="submit" name="consent" value="deny">'.esc_html__('Cancel', 'gds-mcp').'</button>'
            .'</form>'
            .'<p class="foot"><a href="'.esc_url(wp_logout_url(Server::currentUrl())).'">'
            .esc_html__('Sign in as a different user', 'gds-mcp').'</a></p>';

        throw self::page(__('Authorize MCP access', 'gds-mcp'), $body);
    }

    /**
     * A self-contained page: this runs outside the theme and outside
     * wp-login.php, so it borrows neither.
     */
    private static function page(string $title, string $body, int $status = 200): ResponseException
    {
        $html = '<!doctype html><html '.get_language_attributes().'><head><meta charset="'
            .esc_attr(get_bloginfo('charset')).'">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex">'
            .'<title>'.esc_html($title).'</title><style>'
            .'body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
            .'background:#f0f0f1;color:#3c434a;margin:0;padding:2.5rem 1rem}'
            .'main{max-width:26rem;margin:0 auto;background:#fff;border:1px solid #dcdcde;'
            .'border-radius:4px;padding:1.75rem}'
            .'h1{font-size:1.3rem;margin:0 0 1rem}.lead{margin:0 0 1.25rem}'
            .'dl{margin:0 0 1.5rem;font-size:.875rem}dt{color:#646970;margin-top:.75rem}'
            .'dd{margin:.15rem 0 0;font-weight:600}'
            .'.uri{overflow-wrap:anywhere;font-weight:400;font-family:ui-monospace,monospace}'
            .'button{font:inherit;padding:.5rem 1rem;border-radius:3px;border:1px solid #2271b1;'
            .'background:#f6f7f7;color:#2271b1;cursor:pointer}'
            .'button.primary{background:#2271b1;color:#fff}'
            .'.foot{font-size:.8125rem;margin:1.5rem 0 0}a{color:#2271b1}'
            .'</style></head><body><main><h1>'.esc_html($title).'</h1>'.$body.'</main></body></html>';

        return ResponseException::html($html, $status);
    }
}
