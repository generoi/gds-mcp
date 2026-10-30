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
        if (is_resource($this->listener)) {
            proc_terminate($this->listener);
            proc_close($this->listener);
        }
        if ($this->listenerDir !== '') {
            @unlink($this->listenerDir.'/router.php');
            @unlink($this->listenerDir.'/hit');
            @rmdir($this->listenerDir);
        }
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
     * Send requests to `localhost` through the real HTTP stack with
     * PublicUrlGuard as the only check: core's own (IPv4-only) check is told
     * the host is external, the site is moved off localhost so it is not
     * "the site itself", and the guard resolves localhost to $addresses.
     * localhost resolves through /etc/hosts on every runner, so core needs
     * no DNS.
     *
     * @param  array{v4: list<string>, v6: list<string>, v6_failed: bool}  $addresses
     */
    public static function resolveLocalhostTo(array $addresses): void
    {
        add_filter('http_request_host_is_external', '__return_true');
        add_filter('option_home', fn () => 'https://example.org', 99);
        self::setResolver(static fn (string $host): array => $addresses);
    }

    public static function setResolver(?\Closure $resolver): void
    {
        PublicUrlGuard::resolveForTests($resolver);
    }

    public function test_refuses_a_hostname_with_a_private_ipv6_address(): void
    {
        // The pre_http_request stub would answer before the real HTTP stack.
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        // A public A record next to a unique-local AAAA: core resolves only
        // the IPv4 address.
        self::resolveLocalhostTo(['v4' => ['93.184.216.34'], 'v6' => ['fd00::1'], 'v6_failed' => false]);

        try {
            $error = $this->assertAbilityError('gds/web-fetch', ['url' => 'https://localhost/']);
        } finally {
            self::setResolver(null);
        }

        // One message for every refusal: nothing about the internal address.
        $this->assertStringContainsString('only public https addresses', $error->get_error_message());
        $this->assertStringNotContainsString('fd00', $error->get_error_message());
    }

    public function test_refuses_a_hostname_that_resolves_to_a_private_address(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        self::resolveLocalhostTo(['v4' => ['10.0.0.5'], 'v6' => [], 'v6_failed' => false]);

        try {
            $error = $this->assertAbilityError('gds/web-fetch', ['url' => 'https://localhost/']);
        } finally {
            self::setResolver(null);
        }

        $this->assertStringContainsString('only public https addresses', $error->get_error_message());
    }

    public function test_a_failed_aaaa_lookup_is_refused_without_an_a_record(): void
    {
        self::setResolver(static fn (string $host): array => ['v4' => [], 'v6' => [], 'v6_failed' => true]);
        $withoutA = PublicUrlGuard::urlError('https://servfail.example.com/');
        self::setResolver(static fn (string $host): array => ['v4' => ['93.184.216.34'], 'v6' => [], 'v6_failed' => true]);
        $withA = PublicUrlGuard::urlError('https://servfail.example.com/');
        self::setResolver(null);

        $this->assertNotNull($withoutA);
        $this->assertNull($withA, 'With an A record the fetch goes on, over IPv4 only.');
    }

    /** @var resource|null */
    private $listener = null;

    private string $listenerDir = '';

    /**
     * A plain-HTTP server on 127.0.0.1 that records whether it was reached.
     */
    private function startListener(): int
    {
        if (! function_exists('curl_init') || ! function_exists('proc_open')) {
            $this->markTestSkipped('Needs cURL and proc_open.');
        }
        $this->listenerDir = sys_get_temp_dir().'/gds-mcp-listener-'.uniqid();
        mkdir($this->listenerDir);
        file_put_contents($this->listenerDir.'/router.php', '<?php touch(__DIR__."/hit"); echo "listener";');
        // A random port may be taken; try a few before giving up.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 40000);
            $this->listener = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $this->listenerDir.'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

            for ($i = 0; $i < 20; $i++) {
                // Exited: the port was taken (whatever answers there is not ours).
                if (! is_resource($this->listener) || ! proc_get_status($this->listener)['running']) {
                    break;
                }
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket) {
                    fclose($socket);

                    return $port;
                }
                usleep(100000);
            }

            if (is_resource($this->listener)) {
                proc_terminate($this->listener);
                proc_close($this->listener);
            }
            $this->listener = null;
        }
        $this->markTestSkipped('No local port could be bound for the listener.');
    }

    public function test_the_curl_pin_connects_to_the_given_address(): void
    {
        $port = $this->startListener();

        // A host that resolves nowhere: only the pin can get cURL anywhere.
        $handle = curl_init("http://pin-target.invalid:{$port}/");
        curl_setopt_array($handle, PublicUrlGuard::curlPin('127.0.0.1', $port, true) + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($handle);

        $this->assertSame('listener', $body, curl_error($handle));
        $this->assertSame('127.0.0.1', curl_getinfo($handle, CURLINFO_PRIMARY_IP));
    }

    public function test_each_hop_is_pinned_to_the_address_that_was_checked(): void
    {
        $port = $this->startListener();
        // DNS (here /etc/hosts) says localhost is the listener; the answer the
        // guard checked says otherwise, as after a rebinding.
        self::resolveLocalhostTo(['v4' => ['192.0.2.10'], 'v6' => [], 'v6_failed' => false]);

        $reached = PublicUrlGuard::guard(function () use ($port) {
            // The hooks Requests fires for one hop, around a real cURL handle.
            do_action('requests-requests.before_request', "https://localhost:{$port}/");
            $handle = curl_init("http://localhost:{$port}/");
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 500]);
            do_action('requests-curl.before_send', $handle);

            return curl_exec($handle);
        });
        self::setResolver(null);

        $this->assertFalse($reached, 'cURL went to the checked address, not where DNS points now.');
        $this->assertFileDoesNotExist($this->listenerDir.'/hit');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicIpv6(): array
    {
        return array_map(fn ($ip) => [$ip], array_combine(
            ['site-local', 'IPv4-compatible', 'NAT64 local-use', '6to4', 'Teredo', 'discard-only'],
            ['fec0::1', '::7f00:1', '64:ff9b:1::1', '2002:7f00:1::1', '2001:0:4136:e378::1', '100::1'],
        ));
    }

    /**
     * @dataProvider nonPublicIpv6
     */
    public function test_reserved_ipv6_ranges_are_not_public(string $ip): void
    {
        $this->assertFalse(PublicUrlGuard::isPublicIp($ip));
        $this->assertTrue(PublicUrlGuard::isPublicIp('2606:4700::1111'));
    }

    public function test_every_refusal_reads_the_same(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        // Core refuses this redirect itself, with a message of its own.
        RedirectingTransport::install('https://169.254.169.254/latest/meta-data/');
        try {
            $byCore = $this->assertAbilityError('gds/web-fetch', ['url' => 'https://93.184.216.34/'], 'ssrf_blocked');
        } finally {
            RedirectingTransport::uninstall();
        }
        $literal = $this->assertAbilityError('gds/web-fetch', ['url' => 'https://[fd00::1]/'], 'ssrf_blocked');

        $this->assertSame(PublicUrlGuard::REFUSED, $byCore->get_error_message());
        $this->assertSame(PublicUrlGuard::REFUSED, $literal->get_error_message());
    }

    public function test_refuses_a_redirect_to_a_hostname_with_a_private_address(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeResponse'], 10);
        // The literal 169.254.169.254 core already refuses; a hostname whose
        // AAAA is private only PublicUrlGuard does.
        self::resolveLocalhostTo(['v4' => ['93.184.216.34'], 'v6' => ['fe80::1'], 'v6_failed' => false]);
        RedirectingTransport::install('https://localhost/latest/meta-data/');

        try {
            $this->assertAbilityError('gds/web-fetch', ['url' => 'https://93.184.216.34/']);
        } finally {
            RedirectingTransport::uninstall();
            self::setResolver(null);
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
