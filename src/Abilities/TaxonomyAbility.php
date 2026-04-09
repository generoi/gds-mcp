<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * Registers REST-delegated CRUD abilities for a taxonomy.
 *
 * Registers: gds/{slug}-list, gds/{slug}-read, gds/{slug}-create,
 * gds/{slug}-update, gds/{slug}-delete.
 */
final class TaxonomyAbility
{
    use RestDelegation;

    public function __construct(
        private readonly string $taxonomy,
        private readonly string $route,
        private readonly string $slug,
        private readonly string $label,
    ) {}

    public static function registerAll(): void
    {
        $taxonomies = get_taxonomies(['show_in_rest' => true], 'objects');

        foreach ($taxonomies as $tax) {
            $restBase = $tax->rest_base ?: $tax->name;

            if (str_contains($restBase, '(')) {
                continue;
            }

            $namespace = $tax->rest_namespace ?? 'wp/v2';
            $route = "/{$namespace}/{$restBase}";

            $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($restBase));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');

            if (! $slug) {
                continue;
            }

            $ability = new self($tax->name, $route, $slug, $tax->labels->name);
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
            'description' => "List {$this->label} terms. Delegates to the WordPress REST API.",
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema($this->route),
            'output_schema' => ['type' => 'array', 'items' => self::getRestItemOutputSchema($this->route)],
            'permission_callback' => '__return_true',
            'execute_callback' => [$this, 'executeList'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private function registerRead(): void
    {
        HelpAbility::registerAbility("gds/{$this->slug}-read", [
            'label' => "Read {$this->label}",
            'description' => "Read a single {$this->label} term.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The term ID.']],
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
            'description' => "Create a new {$this->label} term.",
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
            'description' => "Update a {$this->label} term.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => array_merge(
                    ['id' => ['type' => 'integer', 'description' => 'The term ID.']],
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
            'description' => "Delete a {$this->label} term.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The term ID.'],
                    'force' => ['type' => 'boolean', 'description' => 'Required to be true, as terms do not support trashing.', 'default' => false],
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
        $response = self::restGet($this->route, is_array($input) ? $input : []);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : array_map(fn ($item) => (array) $item, $response->get_data());
    }

    public function executeRead(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $id = $input['id'] ?? 0;
        unset($input['id']);

        $response = self::restGet("{$this->route}/{$id}", $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function executeCreate(mixed $input = []): array|WP_Error
    {
        $response = self::restPost($this->route, is_array($input) ? $input : []);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function executeUpdate(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $id = $input['id'] ?? 0;
        unset($input['id']);

        $response = self::restPost("{$this->route}/{$id}", $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function executeDelete(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $id = $input['id'] ?? 0;

        $request = new \WP_REST_Request('DELETE', "{$this->route}/{$id}");
        $request->set_param('force', $input['force'] ?? true);

        $response = rest_do_request($request);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }
}
