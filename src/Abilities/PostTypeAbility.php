<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\AcfAware;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * Registers REST-delegated CRUD abilities for a post type.
 *
 * Registers: gds/{slug}-list, gds/{slug}-read, gds/{slug}-create,
 * gds/{slug}-update, gds/{slug}-delete.
 *
 * Schemas are pulled dynamically from the WordPress REST API routes.
 * All parameters pass through to REST — this class is a thin proxy.
 */
final class PostTypeAbility
{
    use AcfAware;
    use RestDelegation;

    public function __construct(
        private readonly string $postType,
        private readonly string $route,
        private readonly string $slug,
        private readonly string $label,
    ) {}

    /**
     * Register CRUD abilities for all public REST-enabled post types.
     */
    public static function registerAll(): void
    {
        $postTypes = get_post_types(['show_in_rest' => true], 'objects');

        foreach ($postTypes as $type) {
            $restBase = $type->rest_base ?: $type->name;

            // Skip types with parameterized REST bases (e.g. font-faces nested under font-families)
            if (str_contains($restBase, '(')) {
                continue;
            }

            $namespace = $type->rest_namespace ?? 'wp/v2';
            $route = "/{$namespace}/{$restBase}";

            // Sanitize slug for ability name (lowercase alphanumeric + dashes only)
            $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($restBase));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');

            if (! $slug) {
                continue;
            }

            $ability = new self($type->name, $route, $slug, $type->labels->name);
            $ability->register();
        }
    }

    public function register(): void
    {
        $this->registerList();
        $this->registerRead();
        $this->registerCreate();
        $this->registerUpdate();
        $this->registerDelete();
    }

    private function registerList(): void
    {
        HelpAbility::registerAbility("gds/{$this->slug}-list", [
            'label' => "List {$this->label}",
            'description' => "Search and filter {$this->label}. Delegates to the WordPress REST API — accepts all standard REST parameters.",
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema($this->route),
            'output_schema' => self::getRestListOutputSchema($this->route),
            'permission_callback' => '__return_true',
            'execute_callback' => [$this, 'executeList'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private function registerRead(): void
    {
        HelpAbility::registerAbility("gds/{$this->slug}-read", [
            'label' => "Read {$this->label}",
            'description' => "Read a single item from {$this->label} with full content. Delegates to the WordPress REST API.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The post ID to read.'],
                ],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => self::getRestItemOutputSchema($this->route),
            'permission_callback' => '__return_true',
            'execute_callback' => [$this, 'executeRead'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private function registerCreate(): void
    {
        HelpAbility::registerAbility("gds/{$this->slug}-create", [
            'label' => "Create {$this->label}",
            'description' => "Create a new item in {$this->label}. Delegates to the WordPress REST API.",
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema($this->route, method: 'POST'),
            'output_schema' => self::getRestItemOutputSchema($this->route),
            'permission_callback' => '__return_true',
            'execute_callback' => [$this, 'executeCreate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    private function registerUpdate(): void
    {
        HelpAbility::registerAbility("gds/{$this->slug}-update", [
            'label' => "Update {$this->label}",
            'description' => "Update an existing item in {$this->label}. Delegates to the WordPress REST API.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => array_merge(
                    ['id' => ['type' => 'integer', 'description' => 'The post ID to update.']],
                    self::getRestInputSchema($this->route, method: 'POST')['properties'] ?? [],
                ),
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => self::getRestItemOutputSchema($this->route),
            'permission_callback' => '__return_true',
            'execute_callback' => [$this, 'executeUpdate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private function registerDelete(): void
    {
        HelpAbility::registerAbility("gds/{$this->slug}-delete", [
            'label' => "Delete {$this->label}",
            'description' => "Delete an item from {$this->label}. Moves to trash by default; use force=true for permanent deletion.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The post ID to delete.'],
                    'force' => ['type' => 'boolean', 'description' => 'Permanently delete instead of trashing.', 'default' => false],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema' => self::getRestItemOutputSchema($this->route),
            'permission_callback' => '__return_true',
            'execute_callback' => [$this, 'executeDelete'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }

    // ── Execute methods ─────────────────────────────────────────────────────

    public function executeList(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);

        $response = self::restGet($this->route, $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $headers = $response->get_headers();

        return [
            'posts' => array_map(fn ($item) => (array) $item, $response->get_data()),
            'total' => (int) ($headers['X-WP-Total'] ?? 0),
            'pages' => (int) ($headers['X-WP-TotalPages'] ?? 0),
        ];
    }

    public function executeRead(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = $input['id'] ?? 0;
        unset($input['id']);

        $response = self::restGet("{$this->route}/{$id}", $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        return (array) $response->get_data();
    }

    public function executeCreate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);

        // Handle ACF fields separately — REST API doesn't process them via update_field()
        $acfFields = $input['fields'] ?? null;
        unset($input['fields']);

        $response = self::restPost($this->route, $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $data = (array) $response->get_data();

        if ($acfFields && is_array($acfFields)) {
            self::updateAcfFields($data['id'], $acfFields);
        }

        return $data;
    }

    public function executeUpdate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = $input['id'] ?? 0;
        unset($input['id']);

        // Handle ACF fields separately
        $acfFields = $input['fields'] ?? null;
        unset($input['fields']);

        $response = self::restPost("{$this->route}/{$id}", $input);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $data = (array) $response->get_data();

        if ($acfFields && is_array($acfFields)) {
            self::updateAcfFields($data['id'], $acfFields);
        }

        return $data;
    }

    public function executeDelete(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = $input['id'] ?? 0;
        $force = $input['force'] ?? false;

        $request = new \WP_REST_Request('DELETE', "{$this->route}/{$id}");
        $request->set_param('force', $force);

        $response = rest_do_request($request);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        return (array) $response->get_data();
    }
}
