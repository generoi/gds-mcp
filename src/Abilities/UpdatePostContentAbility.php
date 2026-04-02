<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class UpdatePostContentAbility
{
    use PolylangAware;

    public static function register(): void
    {
        wp_register_ability('gds/update-post-content', [
            'label' => 'Update Post Content',
            'description' => 'Update the title, content, excerpt, status, or slug of an existing post or page.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The WordPress post ID to update.',
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => 'New title for the post.',
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => 'New content for the post (raw block markup).',
                    ],
                    'post_excerpt' => [
                        'type' => 'string',
                        'description' => 'New excerpt for the post.',
                    ],
                    'post_status' => [
                        'type' => 'string',
                        'description' => 'New status for the post (draft, publish, pending).',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'post_name' => [
                        'type' => 'string',
                        'description' => 'New URL slug for the post.',
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
                    'content' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'language' => ['type' => ['string', 'null']],
                    'url' => ['type' => 'string'],
                    'modified' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        $postId = $input['post_id'] ?? 0;
        if ($postId && ! current_user_can('edit_post', $postId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to edit this post.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $postId = $input['post_id'] ?? 0;
        $post = get_post($postId);

        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $updateFields = ['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name'];
        $data = ['ID' => $post->ID];
        $hasUpdate = false;

        foreach ($updateFields as $field) {
            if (isset($input[$field])) {
                $data[$field] = $input[$field];
                $hasUpdate = true;
            }
        }

        if (! $hasUpdate) {
            return new WP_Error('no_fields', 'At least one field to update must be provided.');
        }

        $result = wp_update_post($data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        $updated = get_post($result);

        return [
            'id' => $updated->ID,
            'title' => $updated->post_title,
            'content' => $updated->post_content,
            'status' => $updated->post_status,
            'language' => self::getPostLanguage($updated->ID),
            'url' => get_permalink($updated),
            'modified' => $updated->post_modified_gmt,
        ];
    }
}
