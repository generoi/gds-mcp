<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Concerns\PostCopying;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

final class CreateTranslationAbility
{
    use PolylangAware;
    use PostCopying;
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

        $sourceId = (int) ($input['source_id'] ?? 0);
        $language = $input['lang'] ?? '';

        $source = get_post($sourceId);
        if (! $source) {
            return new WP_Error('source_not_found', 'Source post not found.');
        }

        if (! current_user_can('edit_post', $sourceId)) {
            return new WP_Error('forbidden', 'You do not have permission to read this post.', ['status' => 403]);
        }

        $typeObj = get_post_type_object($source->post_type);
        if (! $typeObj || ! current_user_can($typeObj->cap->create_posts)) {
            return new WP_Error('forbidden', 'You do not have permission to create posts of this type.', ['status' => 403]);
        }

        $langError = self::validateLanguage($language);
        if ($langError) {
            return $langError;
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
            'post_title' => $input['title'] ?? $source->post_title,
            'post_content' => $input['content'] ?? $source->post_content,
            'post_excerpt' => $input['excerpt'] ?? $source->post_excerpt,
            'post_status' => $input['status'] ?? 'draft',
        ];

        if (isset($input['slug'])) {
            $postData['post_name'] = $input['slug'];
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

        self::copyPostMeta($sourceId, $newId);
        self::copyPostTaxonomies($sourceId, $newId, $source->post_type);

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
