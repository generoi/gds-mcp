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
