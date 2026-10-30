<?php

namespace GeneroWP\MCP\Concerns;

use GeneroWP\MCP\Undo\RestoreSnapshot;

trait PostCopying
{
    /**
     * WooCommerce identifiers that must be unique, and sales and review stats
     * that belong to one product. A duplicate is a different product, so it
     * starts without them; a translation is the same product, and Polylang for
     * WooCommerce shares them across its languages. (A static property: traits
     * can hold constants only from PHP 8.2.)
     *
     * @var list<string>
     */
    private static array $productIdentityMeta = ['_sku', '_global_unique_id', 'total_sales', '_wc_average_rating', '_wc_rating_count', '_wc_review_count'];

    /**
     * Copy meta from one post to another, including underscore-prefixed meta
     * such as `_wp_page_template`, SEO fields and ACF field references.
     *
     * @param  bool  $duplicate  A new, separate post rather than a translation of the source.
     */
    protected static function copyPostMeta(int $sourceId, int $targetId, bool $duplicate): void
    {
        $allMeta = get_post_meta($sourceId);

        foreach ($allMeta as $key => $values) {
            if (self::isPerPostMeta($key) || ($duplicate && in_array($key, self::$productIdentityMeta, true))) {
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
     * image (set separately, translated where media is), attachment file
     * pointers (two posts sharing one file would delete it with either), Yoast
     * redirect and canonical (the copy would redirect to, or canonicalise to,
     * its source).
     */
    private static function isPerPostMeta(string $key): bool
    {
        $keys = [
            ...RestoreSnapshot::BOOKKEEPING_META,
            '_wp_desired_post_slug', '_thumbnail_id', '_encloseme', '_pingme',
            '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes',
            '_yoast_wpseo_redirect', '_yoast_wpseo_canonical',
        ];

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
