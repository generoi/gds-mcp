<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

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

    public function test_meta_format_asks_for_html(): void
    {
        $this->assertAbilitySuccess('gds/web-fetch', ['url' => 'https://example.com/', 'format' => 'meta']);

        $this->assertSame('text/html', $this->requestArgs['headers']['Accept']);
    }
}
