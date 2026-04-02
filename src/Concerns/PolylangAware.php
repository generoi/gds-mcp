<?php

namespace GeneroWP\MCP\Concerns;

trait PolylangAware
{
    protected static function polylangAvailable(): bool
    {
        return function_exists('pll_get_post_language')
            && function_exists('pll_get_post_translations')
            && function_exists('pll_languages_list');
    }

    protected static function getPostLanguage(int $postId): ?string
    {
        if (! self::polylangAvailable()) {
            return null;
        }

        return pll_get_post_language($postId) ?: null;
    }

    protected static function getPostTranslations(int $postId): ?array
    {
        if (! self::polylangAvailable()) {
            return null;
        }

        return pll_get_post_translations($postId) ?: null;
    }

    /**
     * Get all active languages.
     *
     * @return array<array{slug: string, name: string}>
     */
    protected static function getAllLanguages(): array
    {
        if (! self::polylangAvailable()) {
            return [];
        }

        $languages = pll_languages_list(['fields' => '']);

        return array_map(fn ($lang) => [
            'slug' => $lang->slug,
            'name' => $lang->name,
        ], $languages);
    }

    /**
     * Build a translation summary for a post.
     *
     * Returns a map of language slug => {id, title, status} or null for missing.
     */
    protected static function getTranslationSummary(int $postId): ?array
    {
        if (! self::polylangAvailable()) {
            return null;
        }

        $translations = pll_get_post_translations($postId);
        $languages = self::getAllLanguages();
        $summary = [];

        foreach ($languages as $lang) {
            $slug = $lang['slug'];
            if (! empty($translations[$slug])) {
                $translatedPost = get_post($translations[$slug]);
                $summary[$slug] = $translatedPost ? [
                    'id' => $translatedPost->ID,
                    'title' => $translatedPost->post_title,
                    'status' => $translatedPost->post_status,
                ] : null;
            } else {
                $summary[$slug] = null;
            }
        }

        return $summary;
    }
}
