<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class ListPostTypesAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/list-post-types', [
            'label' => 'List Post Types',
            'description' => 'List all registered post types with their labels and capabilities. Use this to discover available content types before querying with gds/list-posts. Common types include: page, post, wp_template_part (site header, footer, sidebar), wp_block (reusable blocks / synced patterns), and any custom post types (products, news, cases, etc.).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'public_only' => [
                        'type' => 'boolean',
                        'description' => 'Only return public post types.',
                        'default' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_types' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'public' => ['type' => 'boolean'],
                                'hierarchical' => ['type' => 'boolean'],
                                'has_archive' => ['type' => 'boolean'],
                                'count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
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

        if (! current_user_can('read')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to list post types.');
        }

        return true;
    }

    public static function execute(?array $input = []): array
    {
        $publicOnly = $input['public_only'] ?? false;

        $args = $publicOnly ? ['public' => true] : ['show_in_rest' => true];
        $postTypes = get_post_types($args, 'objects');

        // Always include wp_block and wp_template_part for discoverability.
        foreach (['wp_block', 'wp_template_part'] as $type) {
            if (! isset($postTypes[$type]) && post_type_exists($type)) {
                $postTypes[$type] = get_post_type_object($type);
            }
        }

        $result = [];
        foreach ($postTypes as $postType) {
            // Skip internal types that aren't useful for content management.
            if (in_array($postType->name, ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_template'], true)) {
                continue;
            }

            $counts = wp_count_posts($postType->name);

            $result[] = [
                'name' => $postType->name,
                'label' => $postType->labels->name,
                'description' => $postType->description ?: self::describePostType($postType->name),
                'public' => $postType->public,
                'hierarchical' => $postType->hierarchical,
                'has_archive' => (bool) $postType->has_archive,
                'count' => (int) ($counts->publish ?? 0),
            ];
        }

        usort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['post_types' => $result];
    }

    /**
     * Provide helpful descriptions for well-known post types.
     */
    private static function describePostType(string $name): string
    {
        return match ($name) {
            'wp_template_part' => 'Reusable site sections like header, footer, and sidebar. Edit these to update site-wide elements.',
            'wp_block' => 'Reusable blocks (synced patterns) that can be embedded across multiple pages and posts.',
            'page' => 'Static site pages.',
            'post' => 'Blog posts and articles.',
            default => '',
        };
    }
}
