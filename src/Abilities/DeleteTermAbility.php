<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class DeleteTermAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/terms-delete', [
            'label' => 'Delete Term',
            'description' => 'Permanently delete a taxonomy term. Posts assigned to this term will be reassigned to the default term (if any).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'term_id' => [
                        'type' => 'integer',
                        'description' => 'The term ID to delete.',
                    ],
                    'taxonomy' => [
                        'type' => 'string',
                        'description' => 'Taxonomy slug (e.g. category, post_tag, product_brand).',
                    ],
                ],
                'required' => ['term_id', 'taxonomy'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'deleted' => ['type' => 'boolean'],
                    'term_id' => ['type' => 'integer'],
                    'taxonomy' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => true,
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
            return new WP_Error('insufficient_capability', 'You do not have permission to delete terms.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $termId = (int) ($input['term_id'] ?? 0);
        $taxonomy = $input['taxonomy'] ?? '';

        if (! taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', sprintf('Taxonomy "%s" does not exist.', $taxonomy));
        }

        $term = get_term($termId, $taxonomy);
        if (! $term || is_wp_error($term)) {
            return new WP_Error('term_not_found', 'Term not found.');
        }

        $name = $term->name;
        $result = wp_delete_term($termId, $taxonomy);

        if (is_wp_error($result)) {
            return $result;
        }

        if (! $result) {
            return new WP_Error('delete_failed', 'Failed to delete term. It may be the default term.');
        }

        return [
            'deleted' => true,
            'term_id' => $termId,
            'taxonomy' => $taxonomy,
            'name' => $name,
        ];
    }
}
