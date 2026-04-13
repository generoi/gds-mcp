<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

final class CreateTranslationAbility
{
    use PolylangAware;
    use RestDelegation;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-create', [
            'label' => 'Create Translation',
            'description' => 'Create a translated post linked via Polylang. Copies source content, meta, and taxonomy terms. For machine translation, use gds/translations-machine. For term translations, use gds/translations-create-term.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to create a translation of.',
                    ],
                    'lang' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Translated title. Defaults to source title.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Translated content (raw block markup). Defaults to source content.',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'Translated excerpt. Defaults to source excerpt.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Status for the new post.',
                        'default' => 'draft',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'URL slug for the new post. Auto-generated if omitted.',
                    ],
                ],
                'required' => ['source_id', 'lang'],
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
        if (! self::polylangAvailable()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $sourceId = $input['source_id'] ?? 0;
        $language = $input['lang'] ?? '';

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
            'title' => $input['title'] ?? $source->post_title,
            'content' => $input['content'] ?? $source->post_content,
            'excerpt' => $input['excerpt'] ?? $source->post_excerpt,
            'status' => $input['status'] ?? 'draft',
        ];

        if (isset($input['slug'])) {
            $postData['slug'] = $input['slug'];
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

        // Return canonical REST response
        $route = self::getRestRoute($source->post_type);
        if ($route) {
            $response = self::restGet("{$route}/{$newId}");
            if (! self::isRestError($response)) {
                return self::restResponseData($response);
            }
        }

        return ['id' => $newId, 'source_id' => $sourceId];
    }
}
