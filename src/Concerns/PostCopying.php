<?php

namespace GeneroWP\MCP\Concerns;

trait PostCopying
{
    /**
     * Copy public (non-underscore-prefixed) meta from one post to another.
     */
    protected static function copyPostMeta(int $sourceId, int $targetId): void
    {
        $allMeta = get_post_meta($sourceId);

        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            foreach ($values as $value) {
                add_post_meta($targetId, $key, maybe_unserialize($value));
            }
        }
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
