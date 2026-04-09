<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;
use WP_Query;

final class BulkUpdatePostsAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/posts-bulk-update', [
            'label' => 'Bulk Update Posts',
            'description' => 'Update status or meta across multiple posts matching a query. Use post_ids for explicit targets, or filter by post_type/status/meta_query to match dynamically. Returns the count of updated posts.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Explicit list of post IDs to update. If set, query filters are ignored.',
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'description' => 'Post type to filter by (used when post_ids is not set).',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter posts by current status (used when post_ids is not set).',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Polylang language slug to filter by (used when post_ids is not set).',
                    ],
                    'set_status' => [
                        'type' => 'string',
                        'description' => 'New status to apply.',
                        'enum' => ['draft', 'publish', 'pending', 'private', 'trash'],
                    ],
                    'set_meta' => [
                        'type' => 'object',
                        'description' => 'Meta key-value pairs to set on each matched post.',
                    ],
                    'delete_meta' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Meta keys to delete from each matched post.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max posts to update (default 50, max 500). Safety cap to prevent runaway updates.',
                        'default' => 50,
                    ],
                    'dry_run' => [
                        'type' => 'boolean',
                        'description' => 'Preview which posts would be affected without making changes.',
                        'default' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'updated' => ['type' => 'integer'],
                    'matched' => ['type' => 'integer'],
                    'dry_run' => ['type' => 'boolean'],
                    'posts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => true,
                    'idempotent' => true,
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

        if (! current_user_can('edit_others_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to bulk update posts.');
        }

        return true;
    }

    public static function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $setStatus = $input['set_status'] ?? null;
        $setMeta = $input['set_meta'] ?? [];
        $deleteMeta = $input['delete_meta'] ?? [];
        $dryRun = $input['dry_run'] ?? false;
        $limit = min((int) ($input['limit'] ?? 50), 500);

        // Must have at least one update action.
        if (! $setStatus && ! $setMeta && ! $deleteMeta) {
            return new WP_Error('no_updates', 'Provide at least one of set_status, set_meta, or delete_meta.');
        }

        $posts = self::resolveTargetPosts($input, $limit);
        if (is_wp_error($posts)) {
            return $posts;
        }

        $results = [];
        $updated = 0;

        foreach ($posts as $post) {
            if (! current_user_can('edit_post', $post->ID)) {
                continue;
            }

            if (! $dryRun) {
                if ($setStatus) {
                    wp_update_post([
                        'ID' => $post->ID,
                        'post_status' => $setStatus,
                    ]);
                }

                foreach ($setMeta as $key => $value) {
                    update_post_meta($post->ID, $key, $value);
                }

                foreach ($deleteMeta as $key) {
                    delete_post_meta($post->ID, $key);
                }

                $updated++;
            }

            // Re-read status after update.
            $currentStatus = $dryRun ? $post->post_status : get_post_status($post->ID);

            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'status' => $currentStatus,
            ];
        }

        return [
            'updated' => $dryRun ? 0 : $updated,
            'matched' => count($results),
            'dry_run' => $dryRun,
            'posts' => $results,
        ];
    }

    /**
     * Resolve target posts from explicit IDs or query filters.
     *
     * @return \WP_Post[]|WP_Error
     */
    private static function resolveTargetPosts(array $input, int $limit): array|WP_Error
    {
        // Explicit post IDs.
        if (! empty($input['post_ids'])) {
            $ids = array_map('intval', $input['post_ids']);
            $ids = array_slice($ids, 0, $limit);

            $posts = array_filter(array_map('get_post', $ids));
            if (empty($posts)) {
                return new WP_Error('no_posts_found', 'None of the specified post IDs exist.');
            }

            return array_values($posts);
        }

        // Query-based resolution.
        if (empty($input['post_type'])) {
            return new WP_Error('missing_filter', 'Provide post_ids or post_type to identify target posts.');
        }

        $queryArgs = [
            'post_type' => $input['post_type'],
            'post_status' => $input['status'] ?? 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'ID',
            'order' => 'ASC',
        ];

        if (function_exists('pll_get_post_language')) {
            $queryArgs['lang'] = $input['language'] ?? '';
        }

        $query = new WP_Query($queryArgs);

        if (empty($query->posts)) {
            return new WP_Error('no_posts_found', 'No posts match the specified filters.');
        }

        return $query->posts;
    }
}
