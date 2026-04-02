<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;
use WP_Query;

final class SearchMediaAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/search-media', [
            'label' => 'Search Media',
            'description' => 'Search the WordPress media library by filename, title, or MIME type. Returns URLs and metadata for matching attachments.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term to match against filename or title.',
                    ],
                    'mime_type' => [
                        'type' => 'string',
                        'description' => 'Filter by MIME type (e.g. image, image/jpeg, application/pdf).',
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
                        'description' => 'Page number.',
                        'default' => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'media' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'filename' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
                                'mime_type' => ['type' => 'string'],
                                'alt' => ['type' => 'string'],
                                'width' => ['type' => 'integer'],
                                'height' => ['type' => 'integer'],
                                'filesize' => ['type' => 'string'],
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

        if (! current_user_can('upload_files')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to access the media library.');
        }

        return true;
    }

    public static function execute(?array $input = []): array
    {
        $queryArgs = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => min($input['per_page'] ?? 20, 100),
            'paged' => $input['page'] ?? 1,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if (! empty($input['search'])) {
            $queryArgs['s'] = $input['search'];
        }

        if (! empty($input['mime_type'])) {
            $queryArgs['post_mime_type'] = $input['mime_type'];
        }

        $query = new WP_Query($queryArgs);

        $media = array_map(function ($post) {
            $metadata = wp_get_attachment_metadata($post->ID) ?: [];

            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'filename' => basename(get_attached_file($post->ID) ?: ''),
                'url' => wp_get_attachment_url($post->ID),
                'mime_type' => $post->post_mime_type,
                'alt' => get_post_meta($post->ID, '_wp_attachment_image_alt', true) ?: '',
                'width' => (int) ($metadata['width'] ?? 0),
                'height' => (int) ($metadata['height'] ?? 0),
                'filesize' => self::getFileSize($post->ID),
            ];
        }, $query->posts);

        return [
            'media' => $media,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ];
    }

    private static function getFileSize(int $attachmentId): string
    {
        $file = get_attached_file($attachmentId);

        if (! $file || ! @is_file($file)) {
            return '';
        }

        return size_format(filesize($file) ?: 0);
    }
}
