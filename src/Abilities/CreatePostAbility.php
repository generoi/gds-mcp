<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\AcfAware;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class CreatePostAbility
{
    use AcfAware;
    use PolylangAware;

    public static function register(): void
    {
        wp_register_ability('gds/posts/create', [
            'label' => 'Create Post',
            'description' => 'Create a new post, page, or any custom post type. Use gds/post-types/list to discover available types.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_type' => [
                        'type' => 'string',
                        'description' => 'Post type slug (page, post, product, wp_block, wp_template_part, etc.).',
                        'default' => 'page',
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => 'Title for the new post.',
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => 'Content (raw block markup).',
                    ],
                    'post_excerpt' => [
                        'type' => 'string',
                        'description' => 'Excerpt.',
                    ],
                    'post_status' => [
                        'type' => 'string',
                        'description' => 'Post status.',
                        'default' => 'draft',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'post_name' => [
                        'type' => 'string',
                        'description' => 'URL slug. Auto-generated if omitted.',
                    ],
                    'post_parent' => [
                        'type' => 'integer',
                        'description' => 'Parent post ID for hierarchical types.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Polylang language slug to assign (e.g. fi, en, sv).',
                    ],
                    'fields' => [
                        'type' => 'object',
                        'description' => 'ACF field values to set (uses update_field() so ACF hooks fire). Keyed by field name. Read acf://fields to discover available fields.',
                    ],
                    'meta' => [
                        'type' => 'object',
                        'description' => 'Raw post meta key-value pairs. Prefer "fields" for ACF fields.',
                    ],
                ],
                'required' => ['post_title'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'post_type' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'edit_url' => ['type' => 'string'],
                    'language' => ['type' => ['string', 'null']],
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

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('edit_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to create posts.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $postData = [
            'post_type' => $input['post_type'] ?? 'page',
            'post_title' => $input['post_title'] ?? '',
            'post_content' => $input['post_content'] ?? '',
            'post_excerpt' => $input['post_excerpt'] ?? '',
            'post_status' => $input['post_status'] ?? 'draft',
        ];

        if (isset($input['post_name'])) {
            $postData['post_name'] = $input['post_name'];
        }

        if (isset($input['post_parent'])) {
            $parentId = (int) $input['post_parent'];
            if ($parentId && ! get_post($parentId)) {
                return new WP_Error('invalid_parent', 'Parent post not found.');
            }
            $postData['post_parent'] = $parentId;
        }

        $postId = wp_insert_post($postData, true);

        if (is_wp_error($postId)) {
            return $postId;
        }

        // Set language if Polylang is active.
        if (! empty($input['language']) && self::polylangAvailable()) {
            pll_set_post_language($postId, $input['language']);
        }

        // Set ACF fields (preferred — fires ACF hooks for relationships, etc.).
        if (! empty($input['fields']) && is_array($input['fields'])) {
            self::updateAcfFields($postId, $input['fields']);
        }

        // Set raw meta (for non-ACF fields).
        if (! empty($input['meta']) && is_array($input['meta'])) {
            foreach ($input['meta'] as $key => $value) {
                update_post_meta($postId, $key, $value);
            }
        }

        $post = get_post($postId);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'post_type' => $post->post_type,
            'url' => get_permalink($post),
            'edit_url' => get_edit_post_link($post->ID, 'raw'),
            'language' => self::getPostLanguage($post->ID),
        ];
    }
}
