<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class CreateTranslationAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-create', [
            'label' => 'Create Translation',
            'description' => 'Create a translated post linked via Polylang. Copies source content, meta, and taxonomy terms. For machine translation, use gds/translations-machine. For term translations, use gds/translations-create-term.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to create a translation of.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => 'Translated title. Defaults to source title.',
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => 'Translated content (raw block markup). Defaults to source content.',
                    ],
                    'post_excerpt' => [
                        'type' => 'string',
                        'description' => 'Translated excerpt. Defaults to source excerpt.',
                    ],
                    'post_status' => [
                        'type' => 'string',
                        'description' => 'Status for the new post.',
                        'default' => 'draft',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'post_name' => [
                        'type' => 'string',
                        'description' => 'URL slug for the new post. Auto-generated if omitted.',
                    ],
                ],
                'required' => ['source_post_id', 'language'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'edit_url' => ['type' => 'string'],
                    'source_post_id' => ['type' => 'integer'],
                    'parent_id' => ['type' => 'integer'],
                    'parent_resolved' => ['type' => 'boolean'],
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

    public static function checkPermission(mixed $input = []): bool|WP_Error
    {
        $input = is_array($input) ? $input : [];
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('edit_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to create posts.');
        }

        $sourceId = $input['source_post_id'] ?? 0;
        if ($sourceId && ! current_user_can('read_post', $sourceId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to read the source post.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! self::polylangAvailable()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $sourceId = $input['source_post_id'] ?? 0;
        $language = $input['language'] ?? '';

        $source = get_post($sourceId);
        if (! $source) {
            return new WP_Error('source_not_found', 'Source post not found.');
        }

        // Validate language.
        $validLanguages = array_column(self::getAllLanguages(), 'slug');
        if (! in_array($language, $validLanguages, true)) {
            return new WP_Error('invalid_language', sprintf(
                'Invalid language "%s". Valid languages: %s',
                $language,
                implode(', ', $validLanguages)
            ));
        }

        // Check if translation already exists.
        $existingTranslations = pll_get_post_translations($sourceId);
        if (! empty($existingTranslations[$language])) {
            $existingId = $existingTranslations[$language];

            return new WP_Error('translation_exists', sprintf(
                'A %s translation already exists (ID: %d).',
                $language,
                $existingId
            ), ['existing_id' => $existingId]);
        }

        // Build the new post data, defaulting to source content.
        $postData = [
            'post_type' => $source->post_type,
            'post_title' => $input['post_title'] ?? $source->post_title,
            'post_content' => $input['post_content'] ?? $source->post_content,
            'post_excerpt' => $input['post_excerpt'] ?? $source->post_excerpt,
            'post_status' => $input['post_status'] ?? 'draft',
        ];

        if (isset($input['post_name'])) {
            $postData['post_name'] = $input['post_name'];
        }

        // Resolve parent hierarchy.
        $parentResolved = true;
        if ($source->post_parent) {
            $parentTranslations = pll_get_post_translations($source->post_parent);
            if (! empty($parentTranslations[$language])) {
                $postData['post_parent'] = $parentTranslations[$language];
            } else {
                $parentResolved = false;
                $postData['post_parent'] = 0;
            }
        }

        $newId = wp_insert_post($postData, true);

        if (is_wp_error($newId)) {
            return $newId;
        }

        // Set language and link translations.
        pll_set_post_language($newId, $language);

        $translations = pll_get_post_translations($sourceId);
        $translations[$language] = $newId;
        pll_save_post_translations($translations);

        // Copy public post meta from source.
        $allMeta = get_post_meta($sourceId);
        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($newId, $key, maybe_unserialize($value));
            }
        }

        // Copy taxonomy terms from source.
        $taxonomies = get_object_taxonomies($source->post_type, 'names');
        foreach ($taxonomies as $taxonomy) {
            // Skip Polylang's internal language/translation taxonomies.
            if (in_array($taxonomy, ['language', 'post_translations'], true)) {
                continue;
            }
            $terms = wp_get_object_terms($sourceId, $taxonomy, ['fields' => 'ids']);
            if (! empty($terms) && ! is_wp_error($terms)) {
                wp_set_object_terms($newId, $terms, $taxonomy);
            }
        }

        $newPost = get_post($newId);

        return [
            'id' => $newPost->ID,
            'title' => $newPost->post_title,
            'status' => $newPost->post_status,
            'language' => $language,
            'url' => get_permalink($newPost),
            'edit_url' => get_edit_post_link($newPost->ID, 'raw'),
            'source_post_id' => $sourceId,
            'parent_id' => (int) $newPost->post_parent,
            'parent_resolved' => $parentResolved,
        ];
    }
}
