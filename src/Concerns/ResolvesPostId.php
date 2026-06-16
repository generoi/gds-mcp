<?php

namespace GeneroWP\MCP\Concerns;

use WP_Error;

/**
 * Resolves an ability `id` input to a numeric post ID.
 *
 * Accepts an integer post id, or a composite template id "{theme}//{slug}"
 * as exposed by /wp/v2/templates and /wp/v2/template-parts. Polylang Pro
 * suffixes translated wp_template_part / wp_block slugs with "___{lang}"
 * (e.g. "footer", "footer___sv"), so each slug is unique per language and no
 * language disambiguation is needed at this layer.
 */
trait ResolvesPostId
{
    protected static function resolvePostId(mixed $rawId): int|WP_Error
    {
        if (is_string($rawId) && str_contains($rawId, '//')) {
            return self::resolveTemplateCompositeId($rawId);
        }

        return (int) ($rawId ?? 0);
    }

    protected static function resolveTemplateCompositeId(string $compositeId): int|WP_Error
    {
        [$theme, $slug] = array_pad(explode('//', $compositeId, 2), 2, '');
        if ($theme === '' || $slug === '') {
            return new WP_Error('invalid_template_id', "Template id '{$compositeId}' is not in the form '{theme}//{slug}'.");
        }

        $themeTerm = get_term_by('name', $theme, 'wp_theme');
        if (! $themeTerm) {
            return new WP_Error(
                'template_not_customized',
                "Template '{$compositeId}' has no DB post — theme '{$theme}' has no wp_theme term, "
                .'so the template is file-based. Edit the theme file directly or customize via the Site Editor first.',
            );
        }

        // Direct DB query: WP_Query / get_posts goes through Polylang's
        // pre_get_posts filter for translated post types (wp_template_part is
        // auto-registered as translated by Polylang Pro), which would hide
        // language variants outside the current request language. The composite
        // id already encodes the language via the "___{lang}" slug suffix, so
        // we look up by post_name + wp_theme term and skip lang filtering.
        global $wpdb;
        $postId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_name = %s
               AND p.post_type IN ('wp_template', 'wp_template_part')
               AND p.post_status IN ('publish', 'auto-draft', 'draft')
               AND tt.term_id = %d
             ORDER BY FIELD(p.post_status, 'publish', 'draft', 'auto-draft'), p.ID DESC
             LIMIT 1",
            $slug,
            $themeTerm->term_id,
        ));

        if (! $postId) {
            return new WP_Error(
                'template_not_customized',
                "Template '{$compositeId}' has no DB post — it's file-based. "
                .'Customize it in the Site Editor first to create an override, or edit the theme file directly.',
            );
        }

        return $postId;
    }
}
