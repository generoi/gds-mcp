<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;
use GeneroWP\MCP\Undo\Reversible;
use GeneroWP\MCP\Undo\Snapshot;
use WP_Error;

/**
 * Generic CRUD abilities for all taxonomies.
 *
 * Registers 5 tools: terms-list, terms-read, terms-create, terms-update, terms-delete
 * with a `taxonomy` parameter instead of one set per taxonomy.
 */
final class GenericTaxonomyAbility
{
    use RestDelegation;
    use Reversible;

    public static function register(): void
    {
        $taxonomies = self::getAvailableTaxonomies();
        $taxEnum = array_keys($taxonomies);
        $taxDescriptions = array_map(
            fn ($slug, $label) => "{$slug} ({$label})",
            array_keys($taxonomies),
            array_values($taxonomies),
        );
        $taxDesc = implode(', ', $taxDescriptions);

        $instance = new self;

        HelpAbility::registerAbility('gds/terms-list', [
            'label' => 'List Terms',
            'description' => "List taxonomy terms. Available taxonomies: {$taxDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => $taxEnum, 'description' => 'Taxonomy to list'],
                    'per_page' => ['type' => 'integer'],
                    'search' => ['type' => 'string'],
                    'orderby' => ['type' => 'string'],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'hide_empty' => ['type' => 'boolean'],
                    '_fields' => ['type' => 'string'],
                ],
                'required' => ['taxonomy'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeList'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/terms-read', [
            'label' => 'Read Term',
            'description' => "Read a single taxonomy term by ID. Available taxonomies: {$taxDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => $taxEnum],
                    'id' => ['type' => 'integer', 'description' => 'Term ID'],
                ],
                'required' => ['taxonomy', 'id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeRead'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/terms-create', [
            'label' => 'Create Term',
            'description' => "Create a new taxonomy term. Available taxonomies: {$taxDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => $taxEnum],
                    'name' => ['type' => 'string', 'description' => 'Term name'],
                    'slug' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parent' => ['type' => 'integer', 'description' => 'Parent term ID (for hierarchical taxonomies)'],
                ],
                'required' => ['taxonomy', 'name'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeCreate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);

        HelpAbility::registerAbility('gds/terms-update', [
            'label' => 'Update Term',
            'description' => "Update an existing taxonomy term. Available taxonomies: {$taxDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => $taxEnum],
                    'id' => ['type' => 'integer', 'description' => 'Term ID'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parent' => ['type' => 'integer'],
                ],
                'required' => ['taxonomy', 'id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeUpdate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/terms-delete', [
            'label' => 'Delete Term',
            'description' => "Delete a taxonomy term. Available taxonomies: {$taxDesc}.",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => $taxEnum],
                    'id' => ['type' => 'integer', 'description' => 'Term ID'],
                    'force' => ['type' => 'boolean', 'description' => 'Force delete (default: false)'],
                ],
                'required' => ['taxonomy', 'id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeDelete'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }

    private static function getAvailableTaxonomies(): array
    {
        $taxonomies = [];
        foreach (get_taxonomies(['show_in_rest' => true], 'objects') as $tax) {
            $restBase = $tax->rest_base ?: $tax->name;
            $taxonomies[$restBase] = $tax->labels->singular_name;
        }

        return $taxonomies;
    }

    private static function resolveRoute(string $restBase): ?string
    {
        foreach (get_taxonomies(['show_in_rest' => true], 'objects') as $tax) {
            $base = $tax->rest_base ?: $tax->name;
            if ($base === $restBase) {
                $namespace = $tax->rest_namespace ?? 'wp/v2';

                return "/{$namespace}/{$base}";
            }
        }

        return null;
    }

    /**
     * Resolve a REST base (e.g. "categories") to the actual taxonomy slug
     * (e.g. "category"). The REST routes use the base, but WP term functions
     * (get_term, wp_insert_term) — and therefore the undo snapshots — need the
     * real slug.
     */
    private static function resolveTaxonomy(string $restBase): string
    {
        foreach (get_taxonomies(['show_in_rest' => true], 'objects') as $tax) {
            if (($tax->rest_base ?: $tax->name) === $restBase) {
                return $tax->name;
            }
        }

        return $restBase;
    }

    public function executeList(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        unset($input['taxonomy']);

        $response = self::restGet($route, $input);

        return self::restResponseOrError($response);
    }

    public function executeRead(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        $id = (int) ($input['id'] ?? 0);
        unset($input['taxonomy'], $input['id']);

        $response = self::restGet("{$route}/{$id}", $input);

        return self::restResponseOrError($response);
    }

    public function executeCreate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        $taxonomy = self::resolveTaxonomy((string) ($input['taxonomy'] ?? ''));
        unset($input['taxonomy']);

        $response = self::restPost($route, $input);
        $result = self::restResponseOrError($response);

        // Undo a create by deleting the new term.
        if (! is_wp_error($result) && ! empty($result['id'])) {
            $result = $this->reversible($result, 'delete-term', [
                'term_id' => (int) $result['id'],
                'taxonomy' => $taxonomy,
            ], "Delete the created term \"{$result['name']}\"");
        }

        return $result;
    }

    public function executeUpdate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $restBase = (string) ($input['taxonomy'] ?? '');
        $route = self::resolveRoute($restBase);
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        $taxonomy = self::resolveTaxonomy($restBase);
        $id = (int) ($input['id'] ?? 0);
        unset($input['taxonomy'], $input['id']);

        // Capture the term's prior fields + meta before overwriting.
        $undo = $id ? Snapshot::termFields($id, $taxonomy) : [];

        $response = self::restPost("{$route}/{$id}", $input);
        $result = self::restResponseOrError($response);

        if (! is_wp_error($result) && $undo) {
            $result = $this->reversible($result, 'restore-term', $undo, "Revert changes to the term \"{$result['name']}\"");
        }

        return $result;
    }

    public function executeDelete(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $restBase = (string) ($input['taxonomy'] ?? '');
        $route = self::resolveRoute($restBase);
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        $taxonomy = self::resolveTaxonomy($restBase);
        $id = (int) ($input['id'] ?? 0);
        $force = $input['force'] ?? false;

        // Terms have no trash, so deletion is permanent. Capture everything
        // needed to recreate the term under its original id (see
        // RestoreSnapshot::recreateTerm).
        $undo = $id ? Snapshot::termForRecreate($id, $taxonomy) : [];

        $request = new \WP_REST_Request('DELETE', "{$route}/{$id}");
        $request->set_param('force', $force);

        $response = rest_do_request($request);
        $result = self::restResponseOrError($response);

        if (! is_wp_error($result) && $undo) {
            $result = $this->reversible($result, 'recreate-term', $undo, "Restore the deleted term \"{$undo['fields']['name']}\"");
        }

        return $result;
    }
}
