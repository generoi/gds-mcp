<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class DeletePostAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/posts/delete', [
            'label' => 'Delete Post',
            'description' => 'Move a post to trash or permanently delete it. By default moves to trash (recoverable). Use force=true for permanent deletion.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to delete.',
                    ],
                    'force' => [
                        'type' => 'boolean',
                        'description' => 'Permanently delete instead of trashing. Default false.',
                        'default' => false,
                    ],
                ],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'trashed' => ['type' => 'boolean'],
                    'deleted' => ['type' => 'boolean'],
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

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        $postId = (int) ($input['post_id'] ?? 0);
        if ($postId && ! current_user_can('delete_post', $postId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to delete this post.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $force = $input['force'] ?? false;

        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $title = $post->post_title;

        $result = wp_delete_post($postId, $force);

        if (! $result) {
            return new WP_Error('delete_failed', 'Failed to delete post.');
        }

        return [
            'id' => $postId,
            'title' => $title,
            'status' => $force ? 'deleted' : 'trash',
            'trashed' => ! $force,
            'deleted' => $force,
        ];
    }
}
