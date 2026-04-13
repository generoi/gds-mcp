<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

final class DuplicatePostAbility
{
    use PolylangAware;
    use RestDelegation;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/posts-duplicate', [
            'label' => 'Duplicate Post',
            'description' => 'Clone a post with its content, meta, and taxonomy terms. The duplicate is created as a draft with a "(Copy)" title suffix. Optionally assign a different language.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to duplicate.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Custom title for the duplicate. Defaults to original title with "(Copy)" suffix.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Status for the duplicate.',
                        'default' => 'draft',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'lang' => [
                        'type' => 'string',
                        'description' => 'Polylang language slug for the duplicate (e.g. fi, en, sv). Omit to inherit the source language.',
                    ],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
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
        $input = (array) ($input ?? []);
        $sourceId = (int) ($input['id'] ?? 0);
        $source = get_post($sourceId);

        if (! $source) {
            return new WP_Error('post_not_found', 'Source post not found.');
        }

        if (! current_user_can('edit_post', $sourceId)) {
            return new WP_Error('forbidden', 'You do not have permission to read this post.', ['status' => 403]);
        }

        $typeObj = get_post_type_object($source->post_type);
        if (! $typeObj || ! current_user_can($typeObj->cap->create_posts)) {
            return new WP_Error('forbidden', 'You do not have permission to create posts of this type.', ['status' => 403]);
        }

        $title = $input['title'] ?? $source->post_title.' (Copy)';
        $status = $input['status'] ?? 'draft';

        $newId = wp_insert_post([
            'post_type' => $source->post_type,
            'post_title' => $title,
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_status' => $status,
            'post_parent' => $source->post_parent,
            'menu_order' => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status' => $source->ping_status,
        ], true);

        if (is_wp_error($newId)) {
            return $newId;
        }

        self::copyMeta($sourceId, $newId);
        self::copyTaxonomies($sourceId, $newId, $source->post_type);

        $thumbnail = get_post_thumbnail_id($sourceId);
        if ($thumbnail) {
            set_post_thumbnail($newId, $thumbnail);
        }

        if (self::polylangAvailable()) {
            $lang = $input['lang'] ?? self::getPostLanguage($sourceId);
            if ($lang) {
                pll_set_post_language($newId, $lang);
            }
        }

        // Return canonical REST response
        $route = self::getRestRoute($source->post_type);
        if ($route) {
            $response = self::restGet("{$route}/{$newId}");
            if (! self::isRestError($response)) {
                return self::restResponseData($response);
            }
        }

        // Fallback if no REST route
        return ['id' => $newId, 'source_id' => $sourceId];
    }

    private static function copyMeta(int $sourceId, int $targetId): void
    {
        $allMeta = get_post_meta($sourceId);

        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            foreach ($values as $value) {
                add_post_meta($targetId, $key, maybe_unserialize($value));
            }
        }
    }

    private static function copyTaxonomies(int $sourceId, int $targetId, string $postType): void
    {
        $taxonomies = get_object_taxonomies($postType, 'names');
        $taxonomies = array_diff($taxonomies, ['language', 'post_translations', 'term_language', 'term_translations']);

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($sourceId, $taxonomy);
            if (! $terms || is_wp_error($terms)) {
                continue;
            }

            wp_set_object_terms($targetId, array_column($terms, 'term_id'), $taxonomy);
        }
    }
}
