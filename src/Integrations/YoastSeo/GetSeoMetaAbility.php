<?php

namespace GeneroWP\MCP\Integrations\YoastSeo;

use WP_Error;

final class GetSeoMetaAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/get-seo-meta', [
            'label' => 'Get SEO Meta',
            'description' => 'Get the Yoast SEO metadata for a post or page: title, meta description, focus keyphrase, canonical URL, and robots settings.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to get SEO meta for.',
                    ],
                ],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'metadesc' => ['type' => 'string'],
                    'focuskw' => ['type' => 'string'],
                    'canonical' => ['type' => 'string'],
                    'noindex' => ['type' => 'boolean'],
                    'nofollow' => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
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
            return new WP_Error('insufficient_capability', 'You do not have permission to view this post\'s SEO data.');
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

        return [
            'post_id' => $post->ID,
            'title' => get_post_meta($postId, '_yoast_wpseo_title', true) ?: '',
            'metadesc' => get_post_meta($postId, '_yoast_wpseo_metadesc', true) ?: '',
            'focuskw' => get_post_meta($postId, '_yoast_wpseo_focuskw', true) ?: '',
            'canonical' => get_post_meta($postId, '_yoast_wpseo_canonical', true) ?: '',
            'noindex' => (bool) get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true),
            'nofollow' => (bool) get_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', true),
        ];
    }
}
