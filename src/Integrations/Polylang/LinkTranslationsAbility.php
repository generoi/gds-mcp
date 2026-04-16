<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

/**
 * Link existing posts in different languages as translations of each other.
 *
 * This is the natural-flow companion to gds/content-create: if the LLM
 * creates several posts (each via `content-create` with its own `lang`),
 * this ability wires them together so Polylang's language switcher jumps
 * between them.
 *
 * The alternative flow, gds/translations-create, takes a single source post
 * and creates the translation in one step — use that when the posts don't
 * exist yet. Use THIS ability when they already do.
 */
final class LinkTranslationsAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-link', [
            'label' => 'Link Translations',
            'description' => 'Link already-existing posts in different languages as translations of each other. '
                .'Each post must already have a Polylang language assigned (via gds/content-create with `lang`). '
                .'Provide a map of language code → post ID. All posts must share the same post_type.'
                ."\n\nExample: {\"translations\": {\"en\": 12, \"fi\": 13, \"sv\": 14}}"
                ."\n\nRelated: gds/translations-create creates a new translation from a source post in one call. "
                .'Use this ability when the posts already exist (e.g. you created them separately).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'translations' => [
                        'type' => 'object',
                        'description' => 'Map of language code → post ID. Each post must already have its language assigned.',
                        'additionalProperties' => ['type' => 'integer'],
                    ],
                ],
                'required' => ['translations'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'linked' => ['type' => 'object'],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public function execute(mixed $input = []): array|WP_Error
    {
        if (! self::polylangAvailable() || ! function_exists('pll_save_post_translations')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $input = (array) ($input ?? []);
        $translations = $input['translations'] ?? null;

        if (! is_array($translations) || ! $translations) {
            return new WP_Error('missing_translations', 'translations must be a non-empty map of language code → post ID.');
        }

        if (count($translations) < 2) {
            return new WP_Error('too_few_translations', 'At least 2 posts (one per language) are required to link as translations.');
        }

        // Normalize keys (lang codes as strings) and values (IDs as ints).
        $normalized = [];
        foreach ($translations as $lang => $id) {
            $normalized[(string) $lang] = (int) $id;
        }

        if ($error = $this->validate($normalized)) {
            return $error;
        }

        // Assign each post the claimed language (idempotent if already correct).
        foreach ($normalized as $lang => $id) {
            pll_set_post_language($id, $lang);
        }

        pll_save_post_translations($normalized);

        return [
            'linked' => $normalized,
            'note' => 'Polylang translation relationship saved. Language switcher will now navigate between these posts.',
        ];
    }

    /** @param array<string, int> $translations */
    private function validate(array $translations): ?WP_Error
    {
        $configured = array_column(self::getAllLanguages(), 'slug');
        $postTypes = [];

        foreach ($translations as $lang => $id) {
            if (! in_array($lang, $configured, true)) {
                return new WP_Error('invalid_language', sprintf(
                    'Unknown language "%s". Configured: %s.',
                    $lang,
                    implode(', ', $configured),
                ));
            }
            if ($id <= 0) {
                return new WP_Error('invalid_post_id', sprintf('Post ID for "%s" must be a positive integer.', $lang));
            }

            $post = get_post($id);
            if (! $post) {
                return new WP_Error('post_not_found', sprintf('Post %d (claimed "%s") does not exist.', $id, $lang));
            }
            $postTypes[$post->post_type] = true;
        }

        if (count($postTypes) > 1) {
            return new WP_Error(
                'mismatched_post_types',
                'All linked posts must share the same post_type. Got: '.implode(', ', array_keys($postTypes)).'.',
            );
        }

        // Duplicate IDs mean the same post was mapped to multiple languages.
        $ids = array_values($translations);
        if (count($ids) !== count(array_unique($ids))) {
            return new WP_Error('duplicate_post_ids', 'The same post ID cannot be used for multiple languages.');
        }

        return null;
    }
}
