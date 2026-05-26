<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Undo\Reversible;
use GeneroWP\MCP\Undo\Snapshot;
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
    use Reversible;

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
                    'confirm_destructive' => [
                        'type' => 'boolean',
                        'description' => 'Set true to confirm relinking posts that already belong to a translation group or have a different language. This unlinks the previously-related posts — translation links have no revisions. Leave unset for new/unlinked posts.',
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

        // Guard destructive relinks. pll_save_post_translations OVERWRITES the
        // group and pll_set_post_language can strip a post from its current
        // group — both unrevisioned and invisible. Refuse to silently orphan
        // existing relationships unless the caller confirms.
        if (empty($input['confirm_destructive'])) {
            $conflicts = $this->destructiveRelinks($normalized);
            if ($conflicts) {
                return new WP_Error(
                    'destructive_relink',
                    "This relink changes or breaks existing translation relationships, which have no revisions:\n- "
                        .implode("\n- ", $conflicts)
                        ."\n\nRe-run with \"confirm_destructive\": true to proceed, or unlink the affected posts first.",
                    ['status' => 409, 'conflicts' => $conflicts],
                );
            }
        }

        // Capture prior languages + translation groups (incl. siblings the
        // relink would orphan) before mutating anything.
        $undo = Snapshot::translationLinkBefore(array_values($normalized));

        // Assign each post the claimed language (idempotent if already correct).
        foreach ($normalized as $lang => $id) {
            pll_set_post_language($id, $lang);
        }

        pll_save_post_translations($normalized);

        $result = [
            'linked' => $normalized,
            'note' => 'Polylang translation relationship saved. Language switcher will now navigate between these posts.',
        ];

        return $this->reversible($result, 'restore-translation-link', $undo, 'Undo the translation re-link');
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

    /**
     * Detect relinks that would change a post's language or orphan posts from
     * an existing translation group.
     *
     * @param  array<string, int>  $translations  lang => post_id (the new group)
     * @return string[] Human-readable conflict descriptions (empty if safe).
     */
    private function destructiveRelinks(array $translations): array
    {
        $conflicts = [];

        foreach ($translations as $lang => $id) {
            $currentLang = function_exists('pll_get_post_language') ? pll_get_post_language($id) : false;
            if ($currentLang && $currentLang !== $lang) {
                $conflicts[] = sprintf(
                    'post %d is currently "%s"; relinking it as "%s" removes it from its current translation group',
                    $id,
                    $currentLang,
                    $lang,
                );
            }

            $existing = function_exists('pll_get_post_translations') ? pll_get_post_translations($id) : [];
            foreach ($existing as $exLang => $exId) {
                $exId = (int) $exId;
                if ($exId === $id) {
                    continue; // the post's own entry, not a sibling
                }
                // A current sibling whose slot is gone or now points elsewhere
                // gets unlinked by this relink.
                if (($translations[(string) $exLang] ?? null) !== $exId) {
                    $conflicts[] = sprintf(
                        'post %d is already linked to post %d ("%s"), which this relink drops',
                        $id,
                        $exId,
                        $exLang,
                    );
                }
            }
        }

        return array_values(array_unique($conflicts));
    }
}
