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

        // Set both body params (for WP core endpoints) and raw JSON body
        // (for third-party endpoints like Gravity Forms that read get_body() directly).
        $request->set_body_params($body);
        $request->set_body(json_encode($body));

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
    /**
     * Deep-convert REST response data to arrays (handles nested stdClass).
     */
    protected static function restResponseData(WP_REST_Response $response): array
    {
        return json_decode(json_encode($response->get_data()), true) ?? [];
    }

    protected static function isRestError(WP_REST_Response $response): bool
    {
        return $response->get_status() >= 400;
    }

    /**
     * Convert a REST error response to a WP_Error.
     */
    protected static function restErrorToWpError(WP_REST_Response $response): WP_Error
    {
        $data = self::restResponseData($response);

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
     * @param  array  $extra  Additional item properties to merge in
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
     * Get the input schema for a REST route endpoint.
     *
     * Pulls parameter definitions from the registered REST route and converts
     * them to a JSON Schema. Keeps only type, description, default, and enum.
     * Collects required fields into a proper JSON Schema required array.
     *
     * Results are cached in a transient to avoid recomputing on every request.
     *
     * @param  string  $route  REST route (e.g. "/wp/v2/pages")
     * @param  array  $extra  Additional properties to merge in
     * @param  string  $method  HTTP method to pull args for (GET, POST, DELETE)
     */
    protected static function getRestInputSchema(string $route, array $extra = [], string $method = 'GET'): array
    {
        $cacheKey = 'gds_mcp_input_schema_'.md5($route.$method.serialize($extra));
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

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

        $args = [];
        foreach ($routeArgs as $endpoint) {
            $methods = $endpoint['methods'] ?? [];
            if (isset($methods[$method]) || (is_string($methods) && str_contains($methods, $method))) {
                $args = $endpoint['args'] ?? [];

                break;
            }
        }

        $properties = [];
        $required = [];
        $allowedKeys = ['type', 'description', 'enum', 'items', 'format'];

        foreach ($args as $name => $def) {
            $properties[$name] = array_intersect_key($def, array_flip($allowedKeys));
            // Ensure type is always present
            if (! isset($properties[$name]['type'])) {
                $properties[$name]['type'] = 'string';
            }
            if (! empty($def['required'])) {
                $required[] = $name;
            }
        }

        // Allow LLMs to send plain strings for title/content/excerpt.
        // The REST schema defines these as type:object ({raw, rendered}),
        // but normalizeInput() in PostTypeAbility wraps strings before dispatch.
        foreach (['title', 'content', 'excerpt'] as $field) {
            if (isset($properties[$field]) && ($properties[$field]['type'] ?? '') === 'object') {
                $properties[$field]['type'] = ['string', 'object'];
                $properties[$field]['description'] = ($properties[$field]['description'] ?? '')
                    .' Accepts a plain string or {raw: "..."} object.';
            }
        }

        $merged = array_merge($properties, $extra);

        $schema = [
            'type' => 'object',
            'properties' => $merged,
            'additionalProperties' => true,
        ];

        if ($required) {
            $schema['required'] = $required;
        }

        set_transient($cacheKey, $schema, DAY_IN_SECONDS);

        return $schema;
    }

    /**
     * Get the output schema for a single REST item response.
     */
    protected static function getRestItemOutputSchema(string $route): array
    {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $routeArgs = $routes[$route] ?? null;

        if (! $routeArgs) {
            return ['type' => 'object', 'additionalProperties' => true];
        }

        foreach ($routeArgs as $endpoint) {
            if (! empty($endpoint['schema']) && is_callable($endpoint['schema'])) {
                $schema = call_user_func($endpoint['schema']);
                if (! empty($schema['properties'])) {
                    return [
                        'type' => 'object',
                        'properties' => $schema['properties'],
                        'additionalProperties' => true,
                    ];
                }
            }
        }

        return ['type' => 'object', 'additionalProperties' => true];
    }
}
