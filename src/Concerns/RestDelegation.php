<?php

namespace GeneroWP\MCP\Concerns;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

trait RestDelegation
{
    /**
     * Make an internal GET request to the WordPress REST API.
     */
    protected static function restGet(string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * Make an internal POST request to the WordPress REST API.
     */
    protected static function restPost(string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Content-Type', 'application/json');
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * Get the REST API route for a post type (e.g. "/wp/v2/pages").
     */
    protected static function getRestRoute(string $postType): ?string
    {
        $typeObject = get_post_type_object($postType);
        if (! $typeObject || ! $typeObject->show_in_rest) {
            return null;
        }

        $namespace = $typeObject->rest_namespace ?? 'wp/v2';
        $restBase = $typeObject->rest_base ?: $typeObject->name;

        return '/'.$namespace.'/'.$restBase;
    }

    /**
     * Check if a REST response indicates an error.
     */
    protected static function isRestError(WP_REST_Response $response): bool
    {
        return $response->get_status() >= 400;
    }

    /**
     * Convert a REST error response to a WP_Error.
     */
    protected static function restErrorToWpError(WP_REST_Response $response): WP_Error
    {
        $data = $response->get_data();

        return new WP_Error(
            $data['code'] ?? 'rest_error',
            $data['message'] ?? 'REST API request failed.',
            ['status' => $response->get_status()],
        );
    }

    /**
     * Get the output schema for a REST route's response.
     *
     * Pulls the item schema from the registered REST controller and wraps
     * it in a list response structure with total/pages.
     *
     * @param  string  $route  REST route (e.g. "/wp/v2/pages")
     * @param  array   $extra  Additional item properties to merge in
     */
    protected static function getRestListOutputSchema(string $route, array $extra = []): array
    {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $routeArgs = $routes[$route] ?? null;

        $itemSchema = ['type' => 'object', 'additionalProperties' => true];

        if ($routeArgs) {
            foreach ($routeArgs as $endpoint) {
                if (! empty($endpoint['schema']) && is_callable($endpoint['schema'])) {
                    $schema = call_user_func($endpoint['schema']);
                    if (! empty($schema['properties'])) {
                        $itemSchema = [
                            'type' => 'object',
                            'properties' => array_merge($schema['properties'], $extra),
                            'additionalProperties' => true,
                        ];
                    }

                    break;
                }
            }
        }

        return [
            'type' => 'object',
            'properties' => [
                'posts' => ['type' => 'array', 'items' => $itemSchema],
                'total' => ['type' => 'integer'],
                'pages' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * Get the input schema for a REST route's GET endpoint.
     *
     * Pulls parameter definitions from the registered REST route and converts
     * them to a JSON Schema suitable for the Abilities API input_schema.
     * Strips internal callbacks (sanitize_callback, validate_callback) that
     * aren't valid JSON Schema.
     *
     * @param  string  $route  REST route (e.g. "/wp/v2/pages")
     * @param  array   $extra  Additional properties to merge in
     */
    protected static function getRestInputSchema(string $route, array $extra = []): array
    {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $routeArgs = $routes[$route] ?? null;

        if (! $routeArgs) {
            return [
                'type' => 'object',
                'properties' => $extra,
                'additionalProperties' => true,
            ];
        }

        // Find the GET (list) endpoint — first endpoint with GET method
        $args = [];
        foreach ($routeArgs as $endpoint) {
            $methods = $endpoint['methods'] ?? [];
            if (isset($methods['GET']) || (is_string($methods) && str_contains($methods, 'GET'))) {
                $args = $endpoint['args'] ?? [];

                break;
            }
        }

        // Convert REST args to JSON Schema properties, stripping PHP callbacks
        $properties = [];
        $internalKeys = ['sanitize_callback', 'validate_callback', 'required'];

        foreach ($args as $name => $schema) {
            $properties[$name] = array_diff_key($schema, array_flip($internalKeys));
        }

        return [
            'type' => 'object',
            'properties' => array_merge($properties, $extra),
            'additionalProperties' => true,
        ];
    }
}
