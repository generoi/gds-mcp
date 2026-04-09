<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class DuplicatePostAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/posts-duplicate', [
            'label' => 'Duplicate Post',
            'description' => 'Clone a post with its content, meta, and taxonomy terms. The duplicate is created as a draft with a "(Copy)" title suffix. Optionally assign a different language for multilingual workflows.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to duplicate.',
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => 'Custom title for the duplicate. Defaults to original title with "(Copy)" suffix.',
                    ],
                    'post_status' => [
                        'type' => 'string',
                        'description' => 'Status for the duplicate.',
                        'default' => 'draft',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Polylang language slug for the duplicate (e.g. fi, en, sv). Omit to inherit the source language.',
                    ],
                ],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'source_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'post_type' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'edit_url' => ['type' => 'string'],
                    'language' => ['type' => ['string', 'null']],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    public function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $sourceId = $input['post_id'] ?? 0;
        $source = get_post($sourceId);

        if (! $source) {
            return new WP_Error('post_not_found', 'Source post not found.');
        }

        $title = $input['post_title'] ?? $source->post_title.' (Copy)';
        $status = $input['post_status'] ?? 'draft';

        // Create the duplicate.
        $newPostData = [
            'post_type' => $source->post_type,
            'post_title' => $title,
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_status' => $status,
            'post_parent' => $source->post_parent,
            'menu_order' => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status' => $source->ping_status,
        ];

        $newId = wp_insert_post($newPostData, true);
        if (is_wp_error($newId)) {
            return $newId;
        }

        // Copy public meta.
        self::copyMeta($sourceId, $newId);

        // Copy taxonomy terms.
        self::copyTaxonomies($sourceId, $newId, $source->post_type);

        // Copy featured image.
        $thumbnail = get_post_thumbnail_id($sourceId);
        if ($thumbnail) {
            set_post_thumbnail($newId, $thumbnail);
        }

        // Set language.
        if (self::polylangAvailable()) {
            $language = $input['language'] ?? self::getPostLanguage($sourceId);
            if ($language) {
                pll_set_post_language($newId, $language);
            }
        }

        $post = get_post($newId);

        return [
            'id' => $post->ID,
            'source_id' => $sourceId,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'post_type' => $post->post_type,
            'url' => get_permalink($post),
            'edit_url' => get_edit_post_link($post->ID, 'raw'),
            'language' => self::getPostLanguage($post->ID),
        ];
    }

    /**
     * Copy public post meta from source to target.
     */
    private static function copyMeta(int $sourceId, int $targetId): void
    {
        $allMeta = get_post_meta($sourceId);

        foreach ($allMeta as $key => $values) {
            // Skip private/internal meta.
            if (str_starts_with($key, '_')) {
                continue;
            }

            foreach ($values as $value) {
                add_post_meta($targetId, $key, maybe_unserialize($value));
            }
        }
    }

    /**
     * Copy taxonomy terms from source to target.
     */
    private static function copyTaxonomies(int $sourceId, int $targetId, string $postType): void
    {
        $taxonomies = get_object_taxonomies($postType, 'names');
        // Exclude Polylang internal taxonomies — language is set separately.
        $taxonomies = array_diff($taxonomies, ['language', 'post_translations', 'term_language', 'term_translations']);

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($sourceId, $taxonomy);
            if (! $terms || is_wp_error($terms)) {
                continue;
            }

            $termIds = array_column($terms, 'term_id');
            wp_set_object_terms($targetId, $termIds, $taxonomy);
        }
    }
}
