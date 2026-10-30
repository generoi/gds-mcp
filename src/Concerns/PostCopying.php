<?php

namespace GeneroWP\MCP\Concerns;

trait PostCopying
{
    /**
     * Copy meta from one post to another, including underscore-prefixed meta
     * such as `_wp_page_template`, SEO fields and ACF field references.
     */
    protected static function copyPostMeta(int $sourceId, int $targetId): void
    {
        $allMeta = get_post_meta($sourceId);

        foreach ($allMeta as $key => $values) {
            if (self::isPerPostMeta($key)) {
                continue;
            }

            // add_post_meta() unslashes, so re-slash the raw DB value or
            // backslashes (escaped quotes in JSON, block attributes) are lost.
            foreach ($values as $value) {
                add_post_meta($targetId, $key, wp_slash(maybe_unserialize($value)));
            }
        }
    }

    /**
     * Meta that belongs to one post's lifecycle and must not follow a copy:
     * edit locks, slug/trash bookkeeping, Polylang internals, the featured
     * image (set separately, translated where media is), and attachment file
     * pointers (two posts sharing one file would delete it with either).
     */
    private static function isPerPostMeta(string $key): bool
    {
        $keys = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_thumbnail_id', '_encloseme', '_pingme', '_wp_attached_file', '_wp_attachment_metadata'];

        return in_array($key, $keys, true) || str_starts_with($key, '_pll_') || str_starts_with($key, '_wp_trash_');
    }

    /**
     * Copy taxonomy terms from one post to another, excluding Polylang internals.
     */
    protected static function copyPostTaxonomies(int $sourceId, int $targetId, string $postType): void
    {
        $taxonomies = get_object_taxonomies($postType, 'names');
        $taxonomies = array_diff($taxonomies, ['language', 'post_translations', 'term_language', 'term_translations']);

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($sourceId, $taxonomy);
            if (! $terms || is_wp_error($terms)) {
                continue;
            }

            wp_set_object_terms($targetId, array_column($terms, 'term_id'), $taxonomy);
        }
    }
}
