<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class ManageTermsAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/terms-manage', [
            'label' => 'Manage Taxonomy Terms',
            'description' => 'List, create, or update taxonomy terms. For translating terms, use gds/translations-create-term.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform.',
                        'enum' => ['list', 'create', 'update'],
                    ],
                    'taxonomy' => [
                        'type' => 'string',
                        'description' => 'Taxonomy slug (e.g. category, product_brand, case_category).',
                    ],
                    'term_id' => [
                        'type' => 'integer',
                        'description' => 'Term ID for update action.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Term name for create/update.',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Term slug for create/update.',
                    ],
                    'parent' => [
                        'type' => 'integer',
                        'description' => 'Parent term ID for hierarchical taxonomies.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Term description.',
                    ],
                ],
                'required' => ['action', 'taxonomy'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'terms' => ['type' => 'array'],
                    'term' => ['type' => 'object'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    public static function checkPermission(mixed $input = []): bool|WP_Error
    {
        $input = is_array($input) ? $input : [];
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('manage_categories')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to manage taxonomy terms.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $action = $input['action'] ?? '';
        $taxonomy = $input['taxonomy'] ?? '';

        if (! taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', sprintf('Taxonomy "%s" does not exist.', $taxonomy));
        }

        return match ($action) {
            'list' => self::listTerms($taxonomy),
            'create' => self::createTerm($taxonomy, $input),
            'update' => self::updateTerm($taxonomy, $input),
            default => new WP_Error('invalid_action', 'Action must be list, create, or update.'),
        };
    }

    private static function listTerms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        if (is_wp_error($terms)) {
            return ['terms' => []];
        }

        return [
            'terms' => array_map(fn ($term) => [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $term->parent,
                'count' => $term->count,
                'description' => $term->description,
            ], $terms),
        ];
    }

    private static function createTerm(string $taxonomy, array $input): array|WP_Error
    {
        $name = $input['name'] ?? '';
        if (empty($name)) {
            return new WP_Error('missing_name', 'Term name is required.');
        }

        $args = [];
        if (isset($input['slug'])) {
            $args['slug'] = sanitize_title($input['slug']);
        }
        if (isset($input['parent'])) {
            $args['parent'] = (int) $input['parent'];
        }
        if (isset($input['description'])) {
            $args['description'] = sanitize_textarea_field($input['description']);
        }

        $result = wp_insert_term(sanitize_text_field($name), $taxonomy, $args);

        if (is_wp_error($result)) {
            return $result;
        }

        $term = get_term($result['term_id'], $taxonomy);

        return [
            'term' => [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $term->parent,
            ],
        ];
    }

    private static function updateTerm(string $taxonomy, array $input): array|WP_Error
    {
        $termId = (int) ($input['term_id'] ?? 0);
        if (! $termId) {
            return new WP_Error('missing_term_id', 'Term ID is required for update.');
        }

        $args = [];
        if (isset($input['name'])) {
            $args['name'] = sanitize_text_field($input['name']);
        }
        if (isset($input['slug'])) {
            $args['slug'] = sanitize_title($input['slug']);
        }
        if (isset($input['parent'])) {
            $args['parent'] = (int) $input['parent'];
        }
        if (isset($input['description'])) {
            $args['description'] = $input['description'];
        }

        $result = wp_update_term($termId, $taxonomy, $args);

        if (is_wp_error($result)) {
            return $result;
        }

        $term = get_term($result['term_id'], $taxonomy);

        return [
            'term' => [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $term->parent,
            ],
        ];
    }
}
