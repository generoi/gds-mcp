<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

/**
 * Create a translated term linked via Polylang.
 * Important for avoiding slug clashes between languages.
 */
final class CreateTermTranslationAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-create-term', [
            'label' => 'Create Term Translation',
            'description' => 'Create a translated taxonomy term linked via Polylang. Important for avoiding slug clashes between languages — each term should have a translated version with a language-appropriate slug.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_term_id' => [
                        'type' => 'integer',
                        'description' => 'The source term ID to create a translation of.',
                    ],
                    'taxonomy' => [
                        'type' => 'string',
                        'description' => 'Taxonomy slug (e.g. category, product_brand).',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Translated term name. Defaults to source name.',
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Translated term slug. Auto-generated from name if omitted.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Translated term description.',
                    ],
                ],
                'required' => ['source_term_id', 'taxonomy', 'language'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'taxonomy' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'source_term_id' => ['type' => 'integer'],
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
        if (! self::polylangAvailable() || ! function_exists('pll_set_term_language')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $sourceTermId = $input['source_term_id'] ?? 0;
        $taxonomy = $input['taxonomy'] ?? '';
        $language = $input['language'] ?? '';

        $sourceTerm = get_term($sourceTermId, $taxonomy);
        if (! $sourceTerm || is_wp_error($sourceTerm)) {
            return new WP_Error('term_not_found', 'Source term not found.');
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
        $existingTranslations = pll_get_term_translations($sourceTermId);
        if (! empty($existingTranslations[$language])) {
            $existingId = $existingTranslations[$language];

            return new WP_Error('translation_exists', sprintf(
                'A %s translation already exists (term ID: %d).',
                $language,
                $existingId
            ), ['existing_id' => $existingId]);
        }

        // Create the translated term.
        $name = $input['name'] ?? $sourceTerm->name;
        $args = [];

        if (isset($input['slug'])) {
            $args['slug'] = $input['slug'];
        }

        if (isset($input['description'])) {
            $args['description'] = $input['description'];
        }

        // Resolve parent: if source has parent, look up parent's translation.
        if ($sourceTerm->parent) {
            $parentTranslations = pll_get_term_translations($sourceTerm->parent);
            if (! empty($parentTranslations[$language])) {
                $args['parent'] = $parentTranslations[$language];
            }
        }

        $result = wp_insert_term($name, $taxonomy, $args);

        if (is_wp_error($result)) {
            return $result;
        }

        $newTermId = $result['term_id'];

        // Set language and link translations.
        pll_set_term_language($newTermId, $language);

        $translations = pll_get_term_translations($sourceTermId);
        $translations[$language] = $newTermId;
        pll_save_term_translations($translations);

        $newTerm = get_term($newTermId, $taxonomy);

        return [
            'id' => $newTerm->term_id,
            'name' => $newTerm->name,
            'slug' => $newTerm->slug,
            'taxonomy' => $taxonomy,
            'language' => $language,
            'source_term_id' => $sourceTermId,
        ];
    }
}
