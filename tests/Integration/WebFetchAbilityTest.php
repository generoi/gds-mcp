<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Http\PublicUrlGuard;
use GeneroWP\MCP\Tests\AbilityTestCase;
use GeneroWP\MCP\Tests\Fixtures\RedirectingTransport;

class WebFetchAbilityTest extends AbilityTestCase
{
    private const PAGE = <<<'HTML'
<!doctype html>
<html lang="fi">
<head>
<title>Kasvutarinat - Genero</title>
<meta name="description" content="Asiakkaidemme tarinoita">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://example.com/fi/kasvutarinat/">
<link rel="alternate" hreflang="en" href="https://example.com/references/">
<link rel="alternate" hreflang="fi" href="https://example.com/fi/kasvutarinat/">
</head>
<body><p>Body</p></body>
</html>
HTML;

    /** @var array<string, mixed> */
    private array $requestArgs = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('pre_http_request', [$this, 'fakeResponse'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function fakeResponse(mixed $pre, array $args): array
    {
        $this->requestArgs = $args;

        return ['headers' => ['content-type' => 'text/html; charset=UTF-8'], 'body' => self::PAGE, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }

    public function test_meta_format_returns_what_search_engines_read(): void
    {
        $result = $this->assertAbilitySuccess('gds/web-fetch', ['url' => 'https://example.com/fi/kasvutarinat/', 'format' => 'meta']);

        $this->assertSame('Kasvutarinat - Genero', $result['title']);
        $this->assertSame('Asiakkaidemme tarinoita', $result['description']);
        $this->assertSame('fi', $result['lang']);
        $this->assertSame('https://example.com/fi/kasvutarinat/', $result['canonical']);
        $this->assertSame('index, follow', $result['robots']);
        $this->assertSame(['en' => 'https://example.com/references/', 'fi' => 'https://example.com/fi/kasvutarinat/'], $result['hreflang']);
        $this->assertArrayNotHasKey('content', $result);
    }

    public function test_requires_edit_posts(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertAbilityError('gds/web-fetch', ['url' => 'https://example.com/'], 'ability_invalid_permissions');
        $this->assertSame([], $this->requestArgs, 'No request is made.');
    }

    public function test_meta_format_asks_for_html(): void
    {
        $this->assertAbilitySuccess('gds/web-fetch', ['url' => 'https://example.com/', 'format' => 'meta']);

        $this->assertSame('text/html', $this->requestArgs['headers']['Accept']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicLiteralUrls(): array
    {
        return [
            'link-local metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'carrier-grade NAT' => ['https://100.64.0.1/'],
            'IPv6 loopback' => ['https://[::1]/'],
            'IPv6 unique local' => ['https://[fd00::1]/'],
            'IPv6 link-local' => ['https://[fe80::1]/'],
            'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/'],
        ];
    }

    /**
     * @dataProvider nonPublicLiteralUrls
     */
    public function test_refuses_non_public_ip_literals(string $url): void
    {
        $this->assertAbilityError('gds/web-fetch', ['url' => $url], 'ssrf_blocked');
        $this->assertSame([], $this->requestArgs);
    }

    /**
     * A hostname that resolves without DNS (the machine's own name, through
     * /etc/hosts) and that core's IPv4 check is told to let through, so the
     * request reaches the real HTTP stack and only PublicUrlGuard stands in
     * the way.
     */
    public static function hostCoreLetsThrough(): string
    {
        $host = (string) gethostname();
        if ($host === '' || gethostbyname($host) === $host) {
            self::markTestSkipped('The machine name does not resolve locally.');
        }
        add_filter('http_request_host_is_external', '__return_true');

        return $host;
    }

    public function test_refuses_a_hostname_with_a_private_ipv6_address(): void
    {
        // The pre_http_request stub would answer before the real HTTP stack.
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        $host = self::hostCoreLetsThrough();
        // A public A record next to a unique-local AAAA: core resolves only
        // the IPv4 address.
        PublicUrlGuard::$resolver = static fn (string $host): array => ['93.184.216.34', 'fd00::1'];

        try {
            $error = $this->assertAbilityError('gds/web-fetch', ['url' => "https://{$host}/"]);
        } finally {
            PublicUrlGuard::$resolver = null;
        }

        $this->assertStringContainsString('private or reserved address (fd00::1)', $error->get_error_message());
    }

    public function test_refuses_a_hostname_that_resolves_to_a_private_address(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        $host = self::hostCoreLetsThrough();

        $error = $this->assertAbilityError('gds/web-fetch', ['url' => "https://{$host}/"]);

        $this->assertStringContainsString('private or reserved address', $error->get_error_message());
    }

    public function test_refuses_a_redirect_to_a_non_public_address(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        RedirectingTransport::install('https://169.254.169.254/latest/meta-data/');

        try {
            $this->assertAbilityError('gds/web-fetch', ['url' => 'https://93.184.216.34/']);
        } finally {
            RedirectingTransport::uninstall();
        }

        $this->assertSame(['https://93.184.216.34/'], RedirectingTransport::$requested, 'The redirect target is never requested.');
    }

    public function test_follows_a_redirect_to_a_public_address(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        RedirectingTransport::install('https://93.184.216.35/');

        try {
            $result = $this->assertAbilitySuccess('gds/web-fetch', ['url' => 'https://93.184.216.34/']);
        } finally {
            RedirectingTransport::uninstall();
        }

        $this->assertSame('ok', $result['content']);
    }
}
