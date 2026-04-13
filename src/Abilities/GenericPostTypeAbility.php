<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\AcfAware;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * Generic CRUD abilities for all post types.
 *
 * Instead of registering 5 tools per post type (150+ tools), registers 5 generic
 * tools with a `type` parameter: content-list, content-read, content-create,
 * content-update, content-delete.
 */
final class GenericPostTypeAbility
{
    use AcfAware;
    use RestDelegation;

    /**
     * Register the 5 generic content abilities.
     */
    public static function register(): void
    {
        $types = self::getAvailableTypes();
        $typeEnum = array_keys($types);
        $typeDescriptions = array_map(
            fn ($slug, $label) => "{$slug} ({$label})",
            array_keys($types),
            array_values($types),
        );
        $typeDesc = implode(', ', $typeDescriptions);

        $instance = new self;

        HelpAbility::registerAbility('gds/content-list', [
            'label' => 'List Content',
            'description' => "Search and filter content by type. Available types: {$typeDesc}. Use _fields to limit response size (e.g. \"id,title,slug,status\"). Use per_page (max 100), orderby, order, search, status params to filter.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => $typeEnum, 'description' => 'Post type to list'],
                    'per_page' => ['type' => 'integer', 'description' => 'Results per page (max 100)'],
                    'page' => ['type' => 'integer'],
                    'search' => ['type' => 'string', 'description' => 'Search term'],
                    'orderby' => ['type' => 'string', 'description' => 'Sort field: date, modified, title, slug, id'],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'status' => ['type' => 'string', 'description' => 'Filter by status: publish, draft, any'],
                    '_fields' => ['type' => 'string', 'description' => 'Comma-separated fields to include in response'],
                    'lang' => ['type' => 'string', 'description' => 'Language code (if Polylang active)'],
                ],
                'required' => ['type'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeList'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/content-read', [
            'label' => 'Read Content',
            'description' => "Read a single post/page/CPT by ID with full content. Available types: {$typeDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => $typeEnum, 'description' => 'Post type'],
                    'id' => ['type' => 'integer', 'description' => 'Post ID'],
                ],
                'required' => ['type', 'id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeRead'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/content-create', [
            'label' => 'Create Content',
            'description' => "Create a new post/page/CPT. Available types: {$typeDesc}. Fields are plain strings: title=\"My Title\", content=\"<p>Body</p>\", status=\"draft\"|\"publish\". Both title and content are required (non-empty).",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => $typeEnum, 'description' => 'Post type to create'],
                    'title' => ['type' => ['string', 'object'], 'description' => 'Post title (plain string or {raw: "..."})'],
                    'content' => ['type' => ['string', 'object'], 'description' => 'Post content (HTML or block markup)'],
                    'excerpt' => ['type' => ['string', 'object'], 'description' => 'Post excerpt'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'private', 'pending'], 'description' => 'Post status (default: draft)'],
                    'slug' => ['type' => 'string'],
                    'featured_media' => ['type' => 'integer', 'description' => 'Featured image attachment ID'],
                    'lang' => ['type' => 'string', 'description' => 'Language code'],
                ],
                'required' => ['type', 'title'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeCreate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);

        HelpAbility::registerAbility('gds/content-update', [
            'label' => 'Update Content',
            'description' => "Update an existing post/page/CPT. Available types: {$typeDesc}. Requires type and id. Fields are plain strings. Only include fields you want to change.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => $typeEnum, 'description' => 'Post type'],
                    'id' => ['type' => 'integer', 'description' => 'Post ID to update'],
                    'title' => ['type' => ['string', 'object']],
                    'content' => ['type' => ['string', 'object']],
                    'excerpt' => ['type' => ['string', 'object']],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'private', 'pending', 'trash']],
                    'slug' => ['type' => 'string'],
                    'featured_media' => ['type' => 'integer'],
                ],
                'required' => ['type', 'id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeUpdate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/content-delete', [
            'label' => 'Delete Content',
            'description' => "Delete a post/page/CPT. Moves to trash by default; use force=true for permanent deletion. Available types: {$typeDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => $typeEnum, 'description' => 'Post type'],
                    'id' => ['type' => 'integer', 'description' => 'Post ID to delete'],
                    'force' => ['type' => 'boolean', 'description' => 'Permanently delete instead of trashing (default: false)'],
                ],
                'required' => ['type', 'id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeDelete'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }

    /**
     * Get available post types as slug => label.
     */
    private static function getAvailableTypes(): array
    {
        $types = [];
        foreach (get_post_types(['show_in_rest' => true], 'objects') as $type) {
            $restBase = $type->rest_base ?: $type->name;
            if (str_contains($restBase, '(')) {
                continue;
            }
            $types[$restBase] = $type->labels->singular_name;
        }

        return $types;
    }

    /**
     * Resolve type parameter to REST route.
     */
    private static function resolveRoute(string $restBase): ?string
    {
        foreach (get_post_types(['show_in_rest' => true], 'objects') as $type) {
            $base = $type->rest_base ?: $type->name;
            if ($base === $restBase) {
                $namespace = $type->rest_namespace ?? 'wp/v2';

                return "/{$namespace}/{$base}";
            }
        }

        return null;
    }

    // ── Execute methods ─────────────────────────────────────────────

    public function executeList(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['type'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_type', 'Unknown content type: '.($input['type'] ?? ''));
        }
        unset($input['type']);

        $response = self::restGet($route, $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $headers = $response->get_headers();

        return [
            'posts' => self::restResponseData($response),
            'total' => (int) ($headers['X-WP-Total'] ?? 0),
            'pages' => (int) ($headers['X-WP-TotalPages'] ?? 0),
        ];
    }

    public function executeRead(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['type'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_type', 'Unknown content type: '.($input['type'] ?? ''));
        }
        $id = (int) ($input['id'] ?? 0);
        unset($input['type'], $input['id']);

        $response = self::restGet("{$route}/{$id}", $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        return self::restResponseData($response);
    }

    public function executeCreate(mixed $input = []): array|WP_Error
    {
        $input = PostTypeAbility::normalizeInput((array) ($input ?? []));
        $route = self::resolveRoute($input['type'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_type', 'Unknown content type: '.($input['type'] ?? ''));
        }

        $acfFields = $input['fields'] ?? null;
        unset($input['type'], $input['fields']);

        $response = self::restPost($route, $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $data = self::restResponseData($response);

        if ($acfFields && is_array($acfFields)) {
            self::updateAcfFields($data['id'], $acfFields);
        }

        return $data;
    }

    public function executeUpdate(mixed $input = []): array|WP_Error
    {
        $input = PostTypeAbility::normalizeInput((array) ($input ?? []));
        $route = self::resolveRoute($input['type'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_type', 'Unknown content type: '.($input['type'] ?? ''));
        }
        $id = (int) ($input['id'] ?? 0);

        $acfFields = $input['fields'] ?? null;
        unset($input['type'], $input['id'], $input['fields']);

        $response = self::restPost("{$route}/{$id}", $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $data = self::restResponseData($response);

        if ($acfFields && is_array($acfFields)) {
            self::updateAcfFields($data['id'], $acfFields);
        }

        return $data;
    }

    public function executeDelete(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['type'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_type', 'Unknown content type: '.($input['type'] ?? ''));
        }
        $id = (int) ($input['id'] ?? 0);
        $force = $input['force'] ?? false;

        $request = new \WP_REST_Request('DELETE', "{$route}/{$id}");
        $request->set_param('force', $force);

        $response = rest_do_request($request);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        return self::restResponseData($response);
    }
}
