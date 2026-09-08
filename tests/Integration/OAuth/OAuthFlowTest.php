<?php

namespace GeneroWP\MCP\Tests\Integration\OAuth;

use GeneroWP\MCP\OAuth\Authorize;
use GeneroWP\MCP\OAuth\BearerAuth;
use GeneroWP\MCP\OAuth\Clients;
use GeneroWP\MCP\OAuth\Grants;
use GeneroWP\MCP\OAuth\Metadata;
use GeneroWP\MCP\OAuth\ResponseException;
use GeneroWP\MCP\OAuth\Token;
use GeneroWP\MCP\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The authorization code flow end to end, plus the ways it is supposed to
 * fail: a replayed code, a wrong PKCE verifier, a stale refresh token, a
 * revoked grant, and a token pointed at the wrong endpoint.
 */
class OAuthFlowTest extends TestCase
{
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    private string $resource;

    private int $editor;

    protected function setUp(): void
    {
        parent::setUp();

        // Pretty permalinks, so rest_url() produces /wp-json/… paths like a
        // real site rather than an index.php query string.
        $this->set_permalink_structure('/%postname%/');

        $this->resource = untrailingslashit(rest_url('mcp/test-server'));
        add_filter('gds-mcp/oauth_resources', fn (): array => [$this->resource]);

        $this->editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->editor);

        // The audience a bearer token proved is per-request state; tests share
        // a process, so clear it between them.
        $audience = new \ReflectionProperty(BearerAuth::class, 'audience');
        $audience->setAccessible(true);
        $audience->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        // Left in place rather than unset: WordPress reads REQUEST_URI on
        // shutdown (cron) and warns when it is missing entirely.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_AUTHORIZATION']);

        parent::tearDown();
    }

    // ── Happy path ───────────────────────────────────────────────

    public function test_authorization_code_flow_issues_a_usable_bearer_token(): void
    {
        $verifier = 'verifier-'.wp_generate_password(43, false);
        $client = $this->registerClient();

        $redirect = $this->authorize($client['client_id'], $this->challenge($verifier));
        $this->assertStringStartsWith(self::REDIRECT_URI, (string) $redirect->location());

        $query = $this->query($redirect->location());
        $this->assertArrayHasKey('code', $query);
        $this->assertSame('opaque-state', $query['state']);

        $tokens = $this->exchange($client['client_id'], $query['code'], $verifier);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(Grants::ACCESS_TTL, $tokens['expires_in']);
        $this->assertNotEmpty($tokens['refresh_token']);

        $this->assertSame(
            $this->editor,
            $this->authenticate($tokens['access_token']),
            'The bearer token should resolve to the user who consented.'
        );
    }

    public function test_refresh_rotates_the_refresh_token(): void
    {
        $tokens = $this->connect($clientId);

        $_POST = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $clientId,
        ];
        $refreshed = $this->post([Token::class, 'handle'])->data();

        $this->assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);
        $this->assertSame($this->editor, $this->authenticate($refreshed['access_token']));

        // Replaying the spent refresh token must not mint anything.
        $_POST['refresh_token'] = $tokens['refresh_token'];
        $error = $this->post([Token::class, 'handle']);
        $this->assertSame(400, $error->status);
        $this->assertSame('invalid_grant', $error->data()['error']);
    }

    // ── Codes ────────────────────────────────────────────────────

    public function test_authorization_code_cannot_be_replayed(): void
    {
        $verifier = 'verifier-'.wp_generate_password(43, false);
        $client = $this->registerClient();
        $code = $this->query($this->authorize($client['client_id'], $this->challenge($verifier))->location())['code'];

        $this->exchange($client['client_id'], $code, $verifier);

        $_POST = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
        ];
        $error = $this->post([Token::class, 'handle']);

        $this->assertSame(400, $error->status);
        $this->assertSame('invalid_grant', $error->data()['error']);
    }

    public function test_wrong_pkce_verifier_is_rejected(): void
    {
        $client = $this->registerClient();
        $code = $this->query(
            $this->authorize($client['client_id'], $this->challenge('the-real-verifier'))->location()
        )['code'];

        $_POST = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => 'not-the-real-verifier',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
        ];
        $error = $this->post([Token::class, 'handle']);

        $this->assertSame('invalid_grant', $error->data()['error']);
    }

    public function test_code_cannot_be_redeemed_by_another_client(): void
    {
        $verifier = 'verifier-'.wp_generate_password(43, false);
        $client = $this->registerClient();
        $other = $this->registerClient();
        $code = $this->query($this->authorize($client['client_id'], $this->challenge($verifier))->location())['code'];

        $_POST = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $other['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
        ];
        $error = $this->post([Token::class, 'handle']);

        $this->assertSame('invalid_grant', $error->data()['error']);
    }

    // ── Authorization endpoint ───────────────────────────────────

    public function test_pkce_is_required(): void
    {
        $client = $this->registerClient();
        $redirect = $this->authorize($client['client_id'], '', ['code_challenge_method' => '']);

        $this->assertSame('invalid_request', $this->query($redirect->location())['error']);
    }

    public function test_plain_pkce_is_refused(): void
    {
        $client = $this->registerClient();
        $redirect = $this->authorize($client['client_id'], 'a-plain-challenge', ['code_challenge_method' => 'plain']);

        $this->assertSame('invalid_request', $this->query($redirect->location())['error']);
    }

    public function test_unregistered_redirect_uri_is_not_redirected_to(): void
    {
        $client = $this->registerClient();
        $response = $this->authorize($client['client_id'], $this->challenge('v'), [
            'redirect_uri' => 'https://evil.example/callback',
        ]);

        // Never bounce to an address the client did not register — that would
        // hand the code to whoever asked.
        $this->assertNull($response->location());
        $this->assertSame(403, $response->status);
    }

    public function test_unknown_resource_is_refused(): void
    {
        $client = $this->registerClient();
        $redirect = $this->authorize($client['client_id'], $this->challenge('v'), [
            'resource' => 'https://example.org/wp-json/mcp/other-server',
        ]);

        $this->assertSame('invalid_target', $this->query($redirect->location())['error']);
    }

    public function test_denying_consent_reports_access_denied(): void
    {
        $client = $this->registerClient();
        $redirect = $this->authorize($client['client_id'], $this->challenge('v'), ['consent' => 'deny']);

        $this->assertSame('access_denied', $this->query($redirect->location())['error']);
        $this->assertSame('opaque-state', $this->query($redirect->location())['state']);
    }

    public function test_consent_form_requires_a_valid_nonce(): void
    {
        $client = $this->registerClient();
        $response = $this->authorize($client['client_id'], $this->challenge('v'), ['_wpnonce' => 'forged']);

        $this->assertSame(403, $response->status);
        $this->assertNull($response->location());
    }

    public function test_user_without_the_capability_cannot_authorize(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $client = $this->registerClient();
        $response = $this->authorize($client['client_id'], $this->challenge('v'));

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('not allowed', $response->body);
    }

    public function test_logged_out_visitor_is_sent_to_wp_login(): void
    {
        $client = $this->registerClient();
        wp_set_current_user(0);

        $response = $this->authorize($client['client_id'], $this->challenge('v'));

        $this->assertStringContainsString('wp-login.php', (string) $response->location());
    }

    public function test_logged_out_visitor_is_never_bounced_to_the_client(): void
    {
        // Anyone can register a client with any https callback, so an error
        // redirect fired before login would make this endpoint an open
        // redirector wearing the site's own domain.
        $client = $this->registerClient();
        wp_set_current_user(0);

        $response = $this->authorize($client['client_id'], '', ['code_challenge_method' => 'plain']);
        $location = (string) $response->location();

        // The visitor goes to this site's login screen. The callback appears
        // only as a parameter of the address they return to afterwards, which
        // is this site's own authorization endpoint.
        $this->assertStringContainsString('wp-login.php', $location);
        $this->assertSame(
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url($location, PHP_URL_HOST),
            'An anonymous visitor must never be redirected off-site'
        );
    }

    public function test_login_redirect_carries_the_request_but_not_the_consent(): void
    {
        $client = $this->registerClient();
        wp_set_current_user(0);

        $response = $this->authorize($client['client_id'], $this->challenge('v'));
        parse_str((string) wp_parse_url((string) $response->location(), PHP_URL_QUERY), $login);
        parse_str((string) wp_parse_url((string) ($login['redirect_to'] ?? ''), PHP_URL_QUERY), $back);

        // Everything needed to resume, so a consent POST with an expired
        // cookie is not stranded — but not the consent itself, or logging in
        // would authorize without ever showing the form.
        $this->assertSame($client['client_id'], $back['client_id'] ?? null);
        $this->assertSame($this->resource, $back['resource'] ?? null);
        $this->assertArrayHasKey('code_challenge', $back);
        $this->assertArrayNotHasKey('consent', $back);
    }

    public function test_consent_screen_refuses_to_be_framed(): void
    {
        $client = $this->registerClient();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
            'resource' => $this->resource,
            'code_challenge' => $this->challenge('v'),
            'code_challenge_method' => 'S256',
        ];
        $response = $this->capture(fn () => Authorize::handle());

        $this->assertStringContainsString('Authorize MCP access', $response->body);

        $this->assertSame('DENY', $response->headers['X-Frame-Options']);
        $this->assertSame("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
    }

    public function test_a_state_of_zero_still_comes_back(): void
    {
        $client = $this->registerClient();

        $redirect = $this->authorize($client['client_id'], $this->challenge('v'), ['state' => '0']);

        $this->assertSame('0', $this->query($redirect->location())['state']);
    }

    public function test_token_authenticates_on_a_site_in_a_subdirectory(): void
    {
        $token = $this->tokenFor('https://example.org/blog/wp-json/mcp/test-server');
        $this->moveSiteTo('https://example.org/blog');

        $this->assertSame('/blog/wp-json', Metadata::restBase());

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        $_SERVER['REQUEST_URI'] = '/blog/wp-json/mcp/test-server';

        $this->assertSame($this->editor, BearerAuth::authenticate(false));
    }

    public function test_token_authenticates_when_a_proxy_strips_the_site_path(): void
    {
        // WordPress removes the home path only when the request carries it, so
        // a proxy publishing the site under /blog can hand the origin a bare
        // /wp-json URI and WordPress still routes it to REST.
        $token = $this->tokenFor('https://example.org/blog/wp-json/mcp/test-server');
        $this->moveSiteTo('https://example.org/blog');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/test-server';

        $this->assertSame($this->editor, BearerAuth::authenticate(false));
    }

    public function test_token_does_not_cross_between_sites_sharing_an_origin(): void
    {
        // Sites on a subdirectory network share scheme, host and route; the
        // path is the only thing telling their grants apart.
        $token = $this->tokenFor('https://example.org/site-a/wp-json/mcp/test-server');
        $this->moveSiteTo('https://example.org/site-b');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        $_SERVER['REQUEST_URI'] = '/site-a/wp-json/mcp/test-server';

        $this->assertFalse(BearerAuth::authenticate(false));
    }

    public function test_token_authenticates_through_the_rest_route_query_form(): void
    {
        // The front controller is the one place outside the REST base where
        // WordPress reads `rest_route` as a route, and it is how a
        // plain-permalink site addresses REST at all.
        $tokens = $this->connect();

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
        $_SERVER['REQUEST_URI'] = '/?rest_route='.Metadata::routeOf($this->resource);
        $_GET['rest_route'] = Metadata::routeOf($this->resource);

        $this->assertSame($this->editor, BearerAuth::authenticate(false));
    }

    public function test_query_variable_sites_advertise_a_document_per_server(): void
    {
        // Their REST URLs all share the path core gives them, /index.php, so
        // the path cannot tell two servers apart and the route has to.
        $one = 'https://example.org/index.php?rest_route=/mcp/server-one';
        $two = 'https://example.org/index.php?rest_route=/mcp/server-two';
        add_filter('gds-mcp/oauth_resources', fn (): array => [$one, $two]);

        $this->assertStringEndsWith('/mcp/server-one', Metadata::protectedResourceUrl($one));
        $this->assertStringEndsWith('/mcp/server-two', Metadata::protectedResourceUrl($two));

        // …and the document that URL points at has to resolve.
        $document = $this->capture(fn () => Metadata::serveProtectedResource('/mcp/server-two'))->data();
        $this->assertSame($two, $document['resource']);
    }

    public function test_a_rest_base_that_is_only_the_site_path_matches_nothing(): void
    {
        // A filtered-away prefix on a subdirectory install leaves a base of
        // /blog, which is a prefix of every path on the site — it must not be
        // treated as the REST API.
        $this->moveSiteTo('https://example.org/blog');
        add_filter('rest_url', fn (): string => 'https://example.org/blog/', 30);

        $this->assertNull(Metadata::restBase());
        $this->assertNull(Metadata::routeForPath('/blog/wp-admin/admin-ajax.php'));
    }

    public function test_plain_permalinks_have_no_rest_path_to_match(): void
    {
        $tokens = $this->connect();
        $this->set_permalink_structure('');

        $this->assertNull(Metadata::restBase());

        // With no rewrite rules, /wp-json/… is an ordinary front-end request
        // that WordPress never routes to REST, so it must not authenticate.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/test-server';

        $this->assertFalse(BearerAuth::authenticate(false));
    }

    public function test_registration_that_is_still_being_used_is_not_pruned(): void
    {
        $stale = $this->registerClient();
        $live = $this->registerClient();

        // Backdate both past the pruning horizon, then use one of them.
        update_option('gds_mcp_oauth_clients', [
            $stale['client_id'] => time() - (400 * DAY_IN_SECONDS),
            $live['client_id'] => time() - (400 * DAY_IN_SECONDS),
        ]);
        Clients::touch($live['client_id']);

        $this->registerClient();

        $this->assertNull(Clients::get($stale['client_id']));
        $this->assertNotNull(Clients::get($live['client_id']), 'A client in use must survive pruning');
    }

    public function test_audience_check_is_not_skipped_by_an_earlier_successful_filter(): void
    {
        $tokens = $this->connect();
        $this->assertSame($this->editor, $this->authenticate($tokens['access_token']));

        // A third-party auth plugin returning `true` says "authenticated", not
        // "authorized for this route" — the audience still has to be enforced.
        $GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users';

        $this->assertInstanceOf(\WP_Error::class, BearerAuth::enforceAudience(true));
    }

    public function test_opaque_state_survives_intact(): void
    {
        // sanitize_text_field() deletes percent-encoded sequences; a client
        // whose state is URL-encoded would get it back mangled and read that
        // as a CSRF failure.
        $state = 'a%2Fb%20c+d~e';
        $client = $this->registerClient();

        $redirect = $this->authorize($client['client_id'], $this->challenge('v'), ['state' => $state]);

        $this->assertSame($state, $this->query($redirect->location())['state']);
    }

    // ── Tokens in use ────────────────────────────────────────────

    public function test_token_only_works_against_the_endpoint_it_was_issued_for(): void
    {
        $tokens = $this->connect();

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';

        $this->assertFalse(
            BearerAuth::authenticate(false),
            'A token scoped to the MCP endpoint must not authenticate elsewhere.'
        );
    }

    public function test_rest_route_parameter_cannot_redirect_a_token_to_another_endpoint(): void
    {
        $tokens = $this->connect();

        // WordPress dispatches REST requests by the `rest_route` query var,
        // which overrides the route the permalink rewrite derives from the
        // path — so a request addressed to the MCP endpoint can be served by
        // any other route. Reading the path alone would authenticate it.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
        $_SERVER['REQUEST_URI'] = Metadata::pathOf($this->resource).'?rest_route=/wp/v2/users';
        $_GET['rest_route'] = '/wp/v2/users';

        $this->assertFalse(BearerAuth::authenticate(false));
    }

    public function test_rest_route_in_the_body_cannot_redirect_a_token_either(): void
    {
        $tokens = $this->connect();

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
        $_SERVER['REQUEST_URI'] = Metadata::pathOf($this->resource);
        $_POST['rest_route'] = '/wp/v2/users';

        $this->assertFalse(BearerAuth::authenticate(false));
    }

    public function test_token_does_not_authenticate_outside_the_rest_api(): void
    {
        $tokens = $this->connect();

        // admin-ajax.php and admin-post.php dispatch on is_user_logged_in()
        // without ever parsing the request, so `rest_authentication_errors`
        // never runs there — the route named in the query string has to count
        // for nothing.
        foreach (['/wp-admin/admin-ajax.php', '/wp-admin/admin-post.php', '/wp-comments-post.php'] as $entry) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
            $_SERVER['REQUEST_URI'] = $entry.'?action=whatever';
            $_GET = ['rest_route' => Metadata::routeOf($this->resource)];

            $this->assertFalse(BearerAuth::authenticate(false), $entry.' must not authenticate');
        }
    }

    public function test_a_host_smuggled_into_the_path_does_not_authenticate(): void
    {
        $tokens = $this->connect();

        // A URL parser reads `//evil.com/wp-json/…` as a host plus path;
        // WordPress reads the whole thing as a path and never routes it to
        // REST. Authenticating it would put the token's user on a request the
        // REST checks never see.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokens['access_token'];
        $_SERVER['REQUEST_URI'] = '//evil.com'.Metadata::pathOf($this->resource);

        $this->assertFalse(BearerAuth::authenticate(false));
    }

    public function test_audience_is_re_checked_against_the_dispatched_route(): void
    {
        $tokens = $this->connect();
        $this->assertSame($this->editor, $this->authenticate($tokens['access_token']));

        // Whatever the token proved at authentication time, the route
        // WordPress ends up dispatching is the one that counts.
        $GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users';
        $error = BearerAuth::enforceAudience(null);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame(401, $error->get_error_data()['status']);

        $GLOBALS['wp']->query_vars['rest_route'] = Metadata::routeOf($this->resource);
        $this->assertNull(BearerAuth::enforceAudience(null));
    }

    public function test_revoking_an_access_token_ends_the_authorization(): void
    {
        $tokens = $this->connect();

        $_POST = ['token' => $tokens['access_token']];
        $this->post([Token::class, 'handleRevocation']);

        $this->assertFalse($this->authenticate($tokens['access_token']));
        $this->assertSame([], Grants::all($this->editor));
    }

    public function test_revoking_a_grant_invalidates_its_tokens(): void
    {
        $tokens = $this->connect();
        $this->assertSame($this->editor, $this->authenticate($tokens['access_token']));

        foreach (array_keys(Grants::all($this->editor)) as $grantId) {
            Grants::revoke($this->editor, (string) $grantId);
        }

        $this->assertFalse($this->authenticate($tokens['access_token']));

        $_POST = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']];
        $this->assertSame('invalid_grant', $this->post([Token::class, 'handle'])->data()['error']);
    }

    public function test_a_revoked_grant_is_not_resurrected_by_a_refresh_in_flight(): void
    {
        // A refresh that read the grant before the revoke landed must not put
        // it back — the whole point of the revoke button.
        $tokens = $this->connect();
        $grantId = array_key_first(Grants::all($this->editor));

        Grants::revoke($this->editor, (string) $grantId);

        $this->assertNull(Grants::issueRefreshToken($this->editor, (string) $grantId));
        $this->assertSame([], Grants::all($this->editor));
        $this->assertFalse($this->authenticate($tokens['access_token']));
    }

    public function test_grants_are_independent_of_one_another(): void
    {
        $first = $this->connect();
        $second = $this->connect();
        $this->assertCount(2, Grants::all($this->editor));

        Grants::revoke($this->editor, (string) array_key_first(Grants::all($this->editor)));

        // Revoking one connection leaves the other working.
        $this->assertCount(1, Grants::all($this->editor));
        $this->assertSame(
            $this->editor,
            $this->authenticate($second['access_token']) ?: $this->authenticate($first['access_token'])
        );
    }

    public function test_losing_the_capability_stops_an_existing_token(): void
    {
        $tokens = $this->connect();
        $this->assertSame($this->editor, $this->authenticate($tokens['access_token']));

        // Someone who leaves is demoted rather than deleted, so their posts
        // keep an author. Their connection must not outlive the demotion.
        (new \WP_User($this->editor))->set_role('subscriber');

        $this->authenticate($tokens['access_token']);
        $GLOBALS['wp']->query_vars['rest_route'] = Metadata::routeOf($this->resource);
        $refused = BearerAuth::enforceAudience(null);

        $this->assertInstanceOf(\WP_Error::class, $refused);
        $this->assertSame(401, $refused->get_error_data()['status']);

        $_POST = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => 'gmc_whatever',
        ];
        $this->assertSame('invalid_grant', $this->post([Token::class, 'handle'])->data()['error']);
    }

    public function test_re_authorizing_reuses_the_same_connection(): void
    {
        $verifier = 'verifier-'.wp_generate_password(43, false);
        $client = $this->registerClient();

        $this->authorize($client['client_id'], $this->challenge($verifier));
        $this->authorize($client['client_id'], $this->challenge($verifier));

        // Otherwise the connections screen fills with identically named rows
        // and revoking the one you recognise leaves the others live.
        $this->assertCount(1, Grants::all($this->editor));
    }

    public function test_a_dead_connection_is_swept_up(): void
    {
        $tokens = $this->connect();
        $grantId = (string) array_key_first(Grants::all($this->editor));

        // Its refresh token is gone and nothing has used it since before one
        // could have lasted.
        $grant = Grants::all($this->editor)[$grantId];
        delete_option('gds_mcp_oauth_rt_'.$grant['refresh']);
        $this->setGrantLastUsed($grantId, time() - (Grants::REFRESH_TTL + DAY_IN_SECONDS));

        Grants::purgeExpired();

        $this->assertSame([], Grants::all($this->editor));
        $this->assertFalse($this->authenticate($tokens['access_token']));
    }

    public function test_unknown_token_authenticates_nobody(): void
    {
        $this->assertFalse($this->authenticate('gmat_not-a-real-token'));
    }

    // ── Challenge and discovery ──────────────────────────────────

    public function test_unauthenticated_mcp_response_is_turned_into_a_challenge(): void
    {
        wp_set_current_user(0);

        $response = BearerAuth::challenge(
            new WP_REST_Response(null, 403),
            null,
            new WP_REST_Request('POST', '/mcp/test-server')
        );

        $this->assertSame(401, $response->get_status());
        $this->assertStringContainsString(
            'resource_metadata="'.Metadata::protectedResourceUrl($this->resource).'"',
            $response->get_headers()['WWW-Authenticate']
        );
    }

    public function test_other_endpoints_are_not_challenged(): void
    {
        wp_set_current_user(0);

        $response = BearerAuth::challenge(
            new WP_REST_Response(null, 401),
            null,
            new WP_REST_Request('GET', '/wp/v2/posts')
        );

        $this->assertArrayNotHasKey('WWW-Authenticate', $response->get_headers());
    }

    public function test_protected_resource_metadata_points_at_the_authorization_server(): void
    {
        $document = $this->capture(
            fn () => Metadata::serveProtectedResource(Metadata::pathOf($this->resource))
        )->data();

        $this->assertSame($this->resource, $document['resource']);
        $this->assertSame([untrailingslashit(home_url())], $document['authorization_servers']);
    }

    public function test_authorization_server_metadata_advertises_pkce_and_registration(): void
    {
        $document = $this->capture(fn () => Metadata::serveAuthorizationServer())->data();

        $this->assertSame(['S256'], $document['code_challenge_methods_supported']);
        $this->assertSame(['authorization_code', 'refresh_token'], $document['grant_types_supported']);
        $this->assertNotEmpty($document['registration_endpoint']);
        $this->assertNotEmpty($document['token_endpoint']);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Run a full connection and return the issued tokens.
     *
     * @param  string|null  $clientId  Receives the client that connected.
     * @return array<string, mixed>
     */
    private function connect(&$clientId = null): array
    {
        $verifier = 'verifier-'.wp_generate_password(43, false);
        $client = $this->registerClient();
        $clientId = $client['client_id'];
        $code = $this->query($this->authorize($clientId, $this->challenge($verifier))->location())['code'];

        return $this->exchange($clientId, $code, $verifier);
    }

    /**
     * Mint an access token for a grant against an arbitrary resource, without
     * walking the flow — for the layouts the flow itself cannot reach here.
     */
    private function tokenFor(string $resource): string
    {
        $grantId = Grants::create($this->editor, [
            'client_id' => 'gmc_'.bin2hex(random_bytes(4)),
            'client_name' => 'Test client',
            'resource' => $resource,
            'scopes' => Metadata::SCOPES,
        ]);

        return Grants::issueAccessToken($this->editor, $grantId);
    }

    /**
     * Backdate a grant, which is otherwise only written through code paths
     * that stamp the current time.
     */
    private function setGrantLastUsed(string $grantId, int $timestamp): void
    {
        $grant = Grants::all($this->editor)[$grantId];
        $grant['last_used'] = $timestamp;
        update_user_meta($this->editor, Grants::META_PREFIX.$grantId, $grant);
    }

    /**
     * Pretend the site lives at another URL, path and all.
     */
    private function moveSiteTo(string $home): void
    {
        add_filter('gds-mcp/oauth_resources', fn (): array => [$home.'/wp-json/mcp/test-server']);
        add_filter('home_url', fn (string $url, string $path): string => $home.$path, 20, 2);
        add_filter('rest_url', fn (string $url, string $path): string => $home.'/wp-json'.$path, 20, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function registerClient(): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = $this->capture(fn () => Clients::handleRegistration((string) wp_json_encode([
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI],
        ])));

        $this->assertSame(201, $response->status);

        return $response->data();
    }

    /**
     * Submit the consent form as the current user.
     *
     * @param  array<string, string>  $overrides
     */
    private function authorize(string $clientId, string $challenge, array $overrides = []): ResponseException
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array_merge([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'state' => 'opaque-state',
            'resource' => $this->resource,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'consent' => 'allow',
            '_wpnonce' => wp_create_nonce('gds-mcp-oauth-consent'),
        ], $overrides);

        return $this->capture(fn () => Authorize::handle());
    }

    /**
     * @return array<string, mixed>
     */
    private function exchange(string $clientId, string $code, string $verifier): array
    {
        $_POST = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
        ];

        $response = $this->post([Token::class, 'handle']);
        $this->assertSame(200, $response->status, $response->body);

        return $response->data();
    }

    /**
     * @param  callable  $handler
     */
    private function post($handler): ResponseException
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        return $this->capture(fn () => $handler());
    }

    /**
     * Resolve a bearer token the way a request to the MCP endpoint would.
     *
     * @return int|false
     */
    private function authenticate(string $token)
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        $_SERVER['REQUEST_URI'] = Metadata::pathOf($this->resource);

        return BearerAuth::authenticate(false);
    }

    /**
     * Every endpoint answers by throwing its response.
     */
    private function capture(callable $handler): ResponseException
    {
        try {
            $handler();
        } catch (ResponseException $response) {
            return $response;
        }

        $this->fail('The endpoint returned without sending a response.');
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private function query(?string $url): array
    {
        parse_str((string) wp_parse_url((string) $url, PHP_URL_QUERY), $query);

        return $query;
    }
}
