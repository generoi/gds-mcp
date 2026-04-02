<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;
use WP_Query;

final class ListPostsAbility
{
    use PolylangAware;

    public static function register(): void
    {
        wp_register_ability('gds/list-posts', [
            'label' => 'List Posts',
            'description' => 'Search and filter posts, pages, and custom post types. Works with any post type including wp_template_part (header, footer), wp_block (reusable blocks / synced patterns), and custom types (use gds/list-post-types to discover available types).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term to match against title and content.',
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'description' => 'Post type slug to filter by.',
                        'default' => 'page',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Polylang language slug to filter by (e.g. fi, en, sv).',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Post status to filter by.',
                        'default' => 'publish',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Number of results per page.',
                        'default' => 20,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Page number for pagination.',
                        'default' => 1,
                        'minimum' => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'posts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'post_type' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'language' => ['type' => ['string', 'null']],
                                'translations' => ['type' => ['object', 'null']],
                                'parent_id' => ['type' => 'integer'],
                                'url' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'total' => ['type' => 'integer'],
                    'pages' => ['type' => 'integer'],
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
            return new WP_Error('insufficient_capability', 'You do not have permission to list posts.');
        }

        return true;
    }

    public static function execute(?array $input = []): array
    {
        $perPage = min($input['per_page'] ?? 20, 100);

        $queryArgs = [
            'post_type' => $input['post_type'] ?? 'page',
            'post_status' => $input['status'] ?? 'publish',
            'posts_per_page' => $perPage,
            'paged' => $input['page'] ?? 1,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if (! empty($input['search'])) {
            $queryArgs['s'] = $input['search'];
        }

        if (self::polylangAvailable()) {
            // Explicit language filter, or '' to disable Polylang's auto-filtering.
            $queryArgs['lang'] = $input['language'] ?? '';
        }

        $query = new WP_Query($queryArgs);

        $posts = array_map(function ($post) {
            $translations = self::getTranslationSummary($post->ID);

            return [
                'id' => $post->ID,
                'post_type' => $post->post_type,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'language' => self::getPostLanguage($post->ID),
                'translations' => $translations,
                'parent_id' => $post->post_parent,
                'url' => get_permalink($post),
            ];
        }, $query->posts);

        return [
            'posts' => $posts,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ];
    }
}
