<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;
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

    public function executeList(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        unset($input['taxonomy']);

        $response = self::restGet($route, $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : self::restResponseData($response);
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

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : self::restResponseData($response);
    }

    public function executeCreate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        unset($input['taxonomy']);

        $response = self::restPost($route, $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : self::restResponseData($response);
    }

    public function executeUpdate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        $id = (int) ($input['id'] ?? 0);
        unset($input['taxonomy'], $input['id']);

        $response = self::restPost("{$route}/{$id}", $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : self::restResponseData($response);
    }

    public function executeDelete(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $route = self::resolveRoute($input['taxonomy'] ?? '');
        if (! $route) {
            return new WP_Error('invalid_taxonomy', 'Unknown taxonomy: '.($input['taxonomy'] ?? ''));
        }
        $id = (int) ($input['id'] ?? 0);
        $force = $input['force'] ?? false;

        $request = new \WP_REST_Request('DELETE', "{$route}/{$id}");
        $request->set_param('force', $force);

        $response = rest_do_request($request);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : self::restResponseData($response);
    }
}
