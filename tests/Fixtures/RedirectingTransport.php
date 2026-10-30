<?php

namespace GeneroWP\MCP\Tests\Fixtures;

use WpOrg\Requests\Transport;

/**
 * A Requests transport that answers the first request with a redirect to
 * {@see self::$location} and any later one with a 200, so tests exercise the
 * redirect handling of the real HTTP stack without a network.
 */
class RedirectingTransport implements Transport
{
    public static string $location = '';

    /** @var list<string> */
    public static array $requested = [];

    public function request($url, $headers = [], $data = [], $options = [])
    {
        self::$requested[] = $url;

        [$headers, $body] = count(self::$requested) === 1
            ? ["HTTP/1.1 302 Found\r\nLocation: ".self::$location."\r\nContent-Length: 0", '']
            : ["HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2", 'ok'];

        // Streamed downloads get the body in the file and only headers back.
        if (! empty($options['filename'])) {
            file_put_contents($options['filename'], $body);

            return $headers;
        }

        return $headers."\r\n\r\n".$body;
    }

    public function request_multiple($requests, $options)
    {
        return [];
    }

    public static function test($capabilities = [])
    {
        return true;
    }

    /**
     * Route the next WP HTTP API call through this transport.
     */
    public static function install(string $location): void
    {
        self::$location = $location;
        self::$requested = [];
        add_action('requests-requests.before_request', [self::class, 'useThisTransport'], 5, 5);
    }

    public static function uninstall(): void
    {
        remove_action('requests-requests.before_request', [self::class, 'useThisTransport'], 5);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function useThisTransport($url, $headers, $data, $type, &$options): void
    {
        $options['transport'] = new self;
    }
}
