<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class ManageRevisionsAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/posts-revisions', [
            'label' => 'Manage Revisions',
            'description' => 'List, view, or restore post revisions. Use to see content history, compare changes, or roll back to a previous version.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform.',
                        'enum' => ['list', 'view', 'restore'],
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to list revisions for (required for list).',
                    ],
                    'revision_id' => [
                        'type' => 'integer',
                        'description' => 'The revision ID to view or restore (required for view/restore).',
                    ],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'revisions' => ['type' => 'array'],
                    'revision' => ['type' => 'object'],
                    'restored' => ['type' => 'object'],
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

        $postId = $input['post_id'] ?? 0;
        $revisionId = $input['revision_id'] ?? 0;

        // For restore, check edit permission on the parent post.
        if ($revisionId && ($input['action'] ?? '') === 'restore') {
            $revision = wp_get_post_revision($revisionId);
            $parentId = $revision ? $revision->post_parent : 0;
            if ($parentId && ! current_user_can('edit_post', $parentId)) {
                return new WP_Error('insufficient_capability', 'You do not have permission to edit this post.');
            }
        }

        if ($postId && ! current_user_can('edit_post', $postId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to view this post\'s revisions.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'list' => self::listRevisions($input),
            'view' => self::viewRevision($input),
            'restore' => self::restoreRevision($input),
            default => new WP_Error('invalid_action', 'Action must be list, view, or restore.'),
        };
    }

    private static function listRevisions(array $input): array|WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        if (! $postId) {
            return new WP_Error('missing_post_id', 'post_id is required for list.');
        }

        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $revisions = wp_get_post_revisions($postId, ['order' => 'DESC']);

        return [
            'post_id' => $postId,
            'post_title' => $post->post_title,
            'revisions' => array_values(array_map(function ($rev) {
                $author = get_userdata($rev->post_author);

                return [
                    'id' => $rev->ID,
                    'date' => $rev->post_modified_gmt,
                    'author' => $author ? $author->display_name : sprintf('User #%d', $rev->post_author),
                    'title' => $rev->post_title,
                    'excerpt' => wp_trim_words(wp_strip_all_tags($rev->post_content), 30),
                ];
            }, $revisions)),
        ];
    }

    private static function viewRevision(array $input): array|WP_Error
    {
        $revisionId = (int) ($input['revision_id'] ?? 0);
        if (! $revisionId) {
            return new WP_Error('missing_revision_id', 'revision_id is required for view.');
        }

        $revision = wp_get_post_revision($revisionId);
        if (! $revision) {
            return new WP_Error('revision_not_found', 'Revision not found.');
        }

        $author = get_userdata($revision->post_author);

        return [
            'revision' => [
                'id' => $revision->ID,
                'parent_id' => $revision->post_parent,
                'date' => $revision->post_modified_gmt,
                'author' => $author ? $author->display_name : sprintf('User #%d', $revision->post_author),
                'title' => $revision->post_title,
                'content' => $revision->post_content,
                'excerpt' => $revision->post_excerpt,
            ],
        ];
    }

    private static function restoreRevision(array $input): array|WP_Error
    {
        $revisionId = (int) ($input['revision_id'] ?? 0);
        if (! $revisionId) {
            return new WP_Error('missing_revision_id', 'revision_id is required for restore.');
        }

        $revision = wp_get_post_revision($revisionId);
        if (! $revision) {
            return new WP_Error('revision_not_found', 'Revision not found.');
        }

        // wp_restore_post_revision requires the revision functions.
        if (! function_exists('wp_restore_post_revision')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        $restoredId = wp_restore_post_revision($revisionId);

        if (! $restoredId) {
            return new WP_Error('restore_failed', 'Failed to restore revision.');
        }

        $post = get_post($revision->post_parent);

        return [
            'restored' => [
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'revision_id' => $revisionId,
                'revision_date' => $revision->post_modified_gmt,
            ],
        ];
    }
}
