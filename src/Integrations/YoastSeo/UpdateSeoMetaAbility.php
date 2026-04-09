<?php

namespace GeneroWP\MCP\Integrations\YoastSeo;

use GeneroWP\MCP\Abilities\HelpAbility;
use WP_Error;

final class UpdateSeoMetaAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/seo-update', [
            'label' => 'Update SEO Meta',
            'description' => 'Update the Yoast SEO metadata for a post or page: title, meta description, focus keyphrase, canonical URL.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to update SEO meta for.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'SEO title. Supports Yoast variables like %%title%% %%sep%% %%sitename%%.',
                    ],
                    'metadesc' => [
                        'type' => 'string',
                        'description' => 'Meta description (recommended under 156 characters).',
                    ],
                    'focuskw' => [
                        'type' => 'string',
                        'description' => 'Focus keyphrase for SEO analysis.',
                    ],
                    'canonical' => [
                        'type' => 'string',
                        'description' => 'Canonical URL override.',
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

    public static function checkPermission(mixed $input = []): bool|WP_Error
    {
        $input = is_array($input) ? $input : [];
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        $postId = $input['post_id'] ?? 0;
        if ($postId && ! current_user_can('edit_post', $postId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to edit this post\'s SEO data.');
        }

        return true;
    }

    public static function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $postId = $input['post_id'] ?? 0;
        $post = get_post($postId);

        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $fields = [
            'title' => '_yoast_wpseo_title',
            'metadesc' => '_yoast_wpseo_metadesc',
            'focuskw' => '_yoast_wpseo_focuskw',
            'canonical' => '_yoast_wpseo_canonical',
        ];

        foreach ($fields as $key => $metaKey) {
            if (isset($input[$key])) {
                $value = $key === 'canonical'
                    ? esc_url_raw($input[$key])
                    : sanitize_text_field($input[$key]);
                update_post_meta($postId, $metaKey, $value);
            }
        }

        return [
            'post_id' => $postId,
            'title' => get_post_meta($postId, '_yoast_wpseo_title', true) ?: '',
            'metadesc' => get_post_meta($postId, '_yoast_wpseo_metadesc', true) ?: '',
            'focuskw' => get_post_meta($postId, '_yoast_wpseo_focuskw', true) ?: '',
            'canonical' => get_post_meta($postId, '_yoast_wpseo_canonical', true) ?: '',
        ];
    }
}
