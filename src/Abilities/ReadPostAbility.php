<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\AcfAware;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class ReadPostAbility
{
    use AcfAware;
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/posts-read', [
            'label' => 'Read Post',
            'description' => 'Read a post with content, ACF fields, meta, taxonomy terms, language, and translations. Use gds/posts-revisions for version history, gds/seo-get for SEO data. Works with any post type (use gds/post-types-list to discover types).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The WordPress post ID to read.',
                    ],
                ],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'post_type' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'excerpt' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer'],
                    'language' => ['type' => ['string', 'null']],
                    'translations' => ['type' => ['object', 'null']],
                    'meta' => ['type' => 'object', 'description' => 'Post meta key-value pairs (public meta only).'],
                    'taxonomies' => ['type' => 'object', 'description' => 'Taxonomy terms assigned to the post, keyed by taxonomy name.'],
                    'modified' => ['type' => 'string'],
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

    public static function checkPermission(mixed $input = []): bool|WP_Error
    {
        $input = is_array($input) ? $input : [];
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        $postId = $input['post_id'] ?? 0;
        if ($postId && ! current_user_can('read_post', $postId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to read this post.');
        }

        return true;
    }

    public static function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $post = get_post($input['post_id'] ?? 0);

        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $author = get_userdata($post->post_author);

        return [
            'id' => $post->ID,
            'post_type' => $post->post_type,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'url' => get_permalink($post),
            'parent_id' => $post->post_parent,
            'author' => post_type_supports($post->post_type, 'author') ? [
                'id' => (int) $post->post_author,
                'name' => $author ? $author->display_name : '',
            ] : null,
            'date' => $post->post_date_gmt,
            'modified' => $post->post_modified_gmt,
            'featured_image' => post_type_supports($post->post_type, 'thumbnail')
                ? self::getFeaturedImage($post->ID) : null,
            'language' => self::getPostLanguage($post->ID),
            'translations' => self::getTranslationSummary($post->ID),
            'fields' => self::getAcfFields($post->ID),
            'meta' => self::getPublicMeta($post->ID),
            'taxonomies' => self::getTaxonomyTerms($post->ID, $post->post_type),
        ];
    }

    /**
     * Get featured image data if set.
     */
    private static function getFeaturedImage(int $postId): ?array
    {
        $thumbnailId = get_post_thumbnail_id($postId);
        if (! $thumbnailId) {
            return null;
        }

        return [
            'id' => (int) $thumbnailId,
            'url' => wp_get_attachment_url($thumbnailId),
            'alt' => get_post_meta($thumbnailId, '_wp_attachment_image_alt', true) ?: '',
        ];
    }

    /**
     * Get public post meta, excluding internal/private keys.
     */
    private static function getPublicMeta(int $postId): array
    {
        $allMeta = get_post_meta($postId);
        $meta = [];

        foreach ($allMeta as $key => $values) {
            // Skip private/internal meta (prefixed with _).
            if (str_starts_with($key, '_')) {
                continue;
            }

            $meta[$key] = count($values) === 1 ? $values[0] : $values;
        }

        return $meta;
    }

    /**
     * Get taxonomy terms assigned to a post.
     */
    private static function getTaxonomyTerms(int $postId, string $postType): array
    {
        $taxonomies = get_object_taxonomies($postType, 'names');
        // Exclude Polylang internal taxonomies.
        $taxonomies = array_diff($taxonomies, ['language', 'post_translations', 'term_language', 'term_translations']);
        $result = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if (! $terms || is_wp_error($terms)) {
                continue;
            }

            $result[$taxonomy] = array_map(fn ($term) => [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ], $terms);
        }

        return $result;
    }
}
