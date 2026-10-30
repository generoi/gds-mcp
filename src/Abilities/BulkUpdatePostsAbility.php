<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Undo\Reversible;
use GeneroWP\MCP\Undo\Snapshot;
use GeneroWP\MCP\Undo\Snapshots\PartialPostSnapshot;
use WP_Error;
use WP_Query;

final class BulkUpdatePostsAbility
{
    use Reversible;

    /**
     * Above this many updated posts we skip undo: the stored snapshot would
     * grow unbounded. The bulk update is still applied, just not reversible.
     */
    private const UNDO_MAX_POSTS = 200;

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
                    'lang' => [
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
                    'errors' => [
                        'type' => 'array',
                        'description' => 'Posts, or parts of a post, that were not written.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'error' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => true,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
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

        // Publishing needs publish_posts, not just edit_post. Check every
        // target up front so a refused publish changes nothing.
        if (in_array($setStatus, ['publish', 'private', 'future'], true)) {
            foreach ($posts as $post) {
                $typeObj = get_post_type_object($post->post_type);
                if (current_user_can('edit_post', $post->ID) && (! $typeObj || ! current_user_can($typeObj->cap->publish_posts))) {
                    return new WP_Error('forbidden', sprintf('You do not have permission to publish posts of type "%s".', $post->post_type), ['status' => 403]);
                }
            }
        }

        // The meta keys this run touches — captured per-post for a partial
        // (merge) restore so we never snapshot full content/meta.
        $touchedMetaKeys = array_merge(array_keys((array) $setMeta), array_values((array) $deleteMeta));

        $results = [];
        $updated = 0;
        $undoItems = [];

        $errors = [];

        foreach ($posts as $post) {
            if (! current_user_can('edit_post', $post->ID)) {
                $errors[] = ['id' => $post->ID, 'error' => 'You do not have permission to edit this post.'];

                continue;
            }
            if ($setStatus === 'trash' && ! current_user_can('delete_post', $post->ID)) {
                $errors[] = ['id' => $post->ID, 'error' => 'You do not have permission to trash this post.'];

                continue;
            }

            if (! $dryRun) {
                // Capture this post's prior status + touched meta before mutating.
                $before = Snapshot::partialPost($post->ID, $touchedMetaKeys);
                $written = false;

                if ($setStatus && $setStatus !== $post->post_status) {
                    // Trashing goes through wp_trash_post() so it is a real,
                    // restorable trash (slug freed, prior status kept).
                    $result = $setStatus === 'trash'
                        ? (wp_trash_post($post->ID) ?: new WP_Error('trash_failed', 'Could not move the post to trash.'))
                        : wp_update_post(['ID' => $post->ID, 'post_status' => $setStatus], true);
                    if (is_wp_error($result)) {
                        $errors[] = ['id' => $post->ID, 'error' => $result->get_error_message()];
                    } else {
                        $written = true;
                    }
                }

                // The meta caps also deny protected keys unless a plugin
                // registered an auth callback for them.
                foreach ($setMeta as $key => $value) {
                    if (! current_user_can('edit_post_meta', $post->ID, $key)) {
                        $errors[] = ['id' => $post->ID, 'error' => sprintf('You do not have permission to edit the meta key "%s".', $key)];

                        continue;
                    }
                    // False also when the value was already that, which writes nothing.
                    $written = update_post_meta($post->ID, $key, wp_slash($value)) !== false || $written;
                }

                foreach ($deleteMeta as $key) {
                    if (! current_user_can('delete_post_meta', $post->ID, $key)) {
                        $errors[] = ['id' => $post->ID, 'error' => sprintf('You do not have permission to delete the meta key "%s".', $key)];

                        continue;
                    }
                    $written = delete_post_meta($post->ID, $key) || $written;
                }

                if ($written && $before) {
                    $updated++;
                    if (get_post_status($post->ID) === 'trash' && $post->post_status !== 'trash') {
                        // Untrash rather than set the status back, so the slug
                        // wp_trash_post() suffixed is restored too.
                        $undoItems[] = ['kind' => 'untrash', 'data' => ['id' => $post->ID, 'status' => $before->status]];
                        $before = new PartialPostSnapshot($before->id, '', $before->metaPrev);
                    }
                    $undoItems[] = ['kind' => 'restore-post-partial', 'data' => $before];
                }
            }

            // Re-read status after update.
            $currentStatus = $dryRun ? $post->post_status : get_post_status($post->ID);

            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'status' => $currentStatus,
            ];
        }

        if (! $dryRun && $updated === 0 && $errors) {
            return new WP_Error('bulk_update_failed', 'No post was updated: '.implode(' ', array_map(fn ($e) => "#{$e['id']}: {$e['error']}", $errors)), ['errors' => $errors]);
        }

        $result = [
            'updated' => $dryRun ? 0 : $updated,
            'matched' => count($results),
            'dry_run' => $dryRun,
            'posts' => $results,
        ];
        if ($errors) {
            $result['errors'] = $errors;
        }

        // Attach undo only for a real run within the snapshot size bound.
        if (! $dryRun && $undoItems) {
            if ($updated > self::UNDO_MAX_POSTS) {
                $result['undo_skipped'] = sprintf(
                    'Undo not recorded: %d posts updated exceeds the %d-post limit for storing a reversible snapshot.',
                    $updated,
                    self::UNDO_MAX_POSTS,
                );
            } else {
                $result = $this->reversible($result, 'bulk', ['items' => $undoItems], sprintf('Revert the bulk update to %d post(s)', $updated));
            }
        }

        return $result;
    }

    /**
     * Resolve target posts from explicit IDs or query filters.
     *
     * @param  array<string, mixed>  $input
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
            $queryArgs['lang'] = $input['lang'] ?? '';
        }

        $query = new WP_Query($queryArgs);

        if (empty($query->posts)) {
            return new WP_Error('no_posts_found', 'No posts match the specified filters.');
        }

        return $query->posts;
    }
}
