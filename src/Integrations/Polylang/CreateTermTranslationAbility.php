<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Undo\Reversible;
use WP_Error;

/**
 * Create a translated term linked via Polylang.
 * Important for avoiding slug clashes between languages.
 */
final class CreateTermTranslationAbility
{
    use PolylangAware;
    use Reversible;

    /**
     * Polylang Pro's ACF integration, which hooks its static
     * on_duplicate_term() to pll_duplicate_term
     * (polylang-pro/src/integrations/ACF/Dispatcher.php).
     */
    private const PRO_ACF_DISPATCHER = 'WP_Syntex\\Polylang_Pro\\Integrations\\ACF\\Dispatcher';

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-create-term', [
            'label' => 'Create Term Translation',
            'description' => 'Create a translated taxonomy term linked via Polylang. Important for avoiding slug clashes between languages — each term should have a translated version with a language-appropriate slug.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_id' => [
                        'type' => 'integer',
                        'description' => 'The source term ID to create a translation of.',
                    ],
                    'taxonomy' => [
                        'type' => 'string',
                        'description' => 'Taxonomy slug (e.g. category, product_brand).',
                    ],
                    'lang' => [
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
                'required' => ['source_id', 'taxonomy', 'lang'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'taxonomy' => ['type' => 'string'],
                    'lang' => ['type' => 'string'],
                    'source_id' => ['type' => 'integer'],
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

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        if (! self::polylangAvailable() || ! function_exists('pll_set_term_language')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $sourceTermId = (int) ($input['source_id'] ?? 0);
        $taxonomy = $input['taxonomy'] ?? '';
        $language = $input['lang'] ?? '';

        $sourceTerm = get_term($sourceTermId, $taxonomy);
        if (! $sourceTerm || is_wp_error($sourceTerm)) {
            return new WP_Error('term_not_found', 'Source term not found.');
        }

        $taxObj = get_taxonomy($taxonomy);
        if (! $taxObj || ! current_user_can($taxObj->cap->edit_terms)) {
            return new WP_Error('forbidden', 'You do not have permission to manage terms in this taxonomy.', ['status' => 403]);
        }

        $langError = self::validateLanguage($language);
        if ($langError) {
            return $langError;
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

        // wp_insert_term() unslashes the name and description.
        $result = wp_insert_term(wp_slash($name), $taxonomy, wp_slash($args));

        if (is_wp_error($result)) {
            return $result;
        }

        $newTermId = $result['term_id'];

        // Set language and link translations.
        pll_set_term_language($newTermId, $language);

        $translations = pll_get_term_translations($sourceTermId);
        $translations[$language] = $newTermId;
        pll_save_term_translations($translations);

        // Term meta follows the way Polylang copies it when a term is
        // translated in wp-admin: pll_duplicate_term runs its meta sync (keys
        // from pll_copy_term_metas, none by default) and, on Polylang Pro, the
        // ACF integration, which copies ACF fields by their translate/copy
        // settings. Without that integration (free Polylang) the rest of the
        // meta — ACF fields included — is copied here, apart from what the
        // action already wrote. With it, copying here would override ACF's
        // "ignore" settings.
        do_action('pll_duplicate_term', $sourceTermId, $newTermId, $language);
        if (! has_action('pll_duplicate_term', [self::PRO_ACF_DISPATCHER, 'on_duplicate_term'])) {
            $written = get_term_meta($newTermId);
            foreach (get_term_meta($sourceTermId) as $key => $values) {
                if (isset($written[$key]) || str_starts_with((string) $key, '_pll_') || str_starts_with((string) $key, 'product_count_')) {
                    continue;
                }
                foreach ($values as $value) {
                    add_term_meta($newTermId, (string) $key, wp_slash(maybe_unserialize($value)));
                }
            }
        }

        $newTerm = get_term($newTermId, $taxonomy);

        $result = [
            'id' => $newTerm->term_id,
            'name' => $newTerm->name,
            'slug' => $newTerm->slug,
            'taxonomy' => $taxonomy,
            'lang' => $language,
            'source_id' => $sourceTermId,
        ];

        return $this->reversible(
            $result,
            'delete-term',
            ['term_id' => $newTermId, 'taxonomy' => $taxonomy],
            'Delete the created term translation',
        );
    }
}
