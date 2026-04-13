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

    /**
     * Validate a language slug against registered Polylang languages.
     *
     * @return \WP_Error|null WP_Error if invalid, null if valid.
     */
    protected static function validateLanguage(string $language): ?\WP_Error
    {
        $validLanguages = array_column(self::getAllLanguages(), 'slug');

        if (! in_array($language, $validLanguages, true)) {
            return new \WP_Error('invalid_language', sprintf(
                'Invalid language "%s". Valid languages: %s',
                $language,
                implode(', ', $validLanguages)
            ));
        }

        return null;
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
}
