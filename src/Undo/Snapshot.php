<?php

namespace GeneroWP\MCP\Undo;

/**
 * Captures the "before" state of an object so a write can be undone.
 *
 * Mutating abilities call a capture helper before they change anything and
 * attach the result to their successful response under the `_undo` key (via
 * {@see self::envelope()}). gds-assistant peels `_undo` off the result — it
 * never reaches the LLM — and stores it on the audit-log row. To undo, it
 * passes the snapshot back through the `gds-mcp/restore_snapshot` filter, which
 * {@see RestoreSnapshot} handles.
 *
 * The snapshot is self-describing: `['kind' => ..., 'data' => [...]]`. Restore
 * is keyed by `kind`. Abilities attach it with the {@see Reversible} trait.
 */
final class Snapshot
{
    /**
     * Full state of a post needed to restore an edit: core fields, all meta,
     * and term relationships. Used to undo updates (content, blocks, bulk,
     * revisions-restore, machine-translate).
     *
     * @return array<string, mixed>
     */
    public static function postFields(int $id): array
    {
        $post = get_post($id);
        if (! $post) {
            return [];
        }

        return [
            'id' => $id,
            'post' => [
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status' => $post->post_status,
                'post_name' => $post->post_name,
                'post_parent' => $post->post_parent,
                'menu_order' => $post->menu_order,
            ],
            'meta' => get_post_meta($id),
            'terms' => self::postTerms($id),
        ];
    }

    /**
     * Minimal capture for a partial (merge) restore: the current status plus
     * the prior values of ONLY the given meta keys. Used by bulk-update so a
     * large batch never snapshots full content/meta and the restore won't
     * clobber meta it didn't change. A key with no value snapshots as null
     * (restore removes it). Pairs with RestoreSnapshot::restorePostPartial().
     *
     * @param  array<int, string>  $metaKeys
     * @return array<string, mixed>
     */
    public static function partialPost(int $id, array $metaKeys): array
    {
        $post = get_post($id);
        if (! $post) {
            return [];
        }

        $metaPrev = [];
        foreach (array_unique($metaKeys) as $key) {
            $values = get_post_meta($id, (string) $key); // [] when absent
            $metaPrev[(string) $key] = $values !== [] ? $values : null;
        }

        return ['id' => $id, 'status' => $post->post_status, 'meta_prev' => $metaPrev];
    }

    /**
     * Capture prior languages + full translation groups for every post a
     * relink touches AND every sibling currently in their groups (so siblings
     * the relink would orphan are restored too). Pairs with
     * RestoreSnapshot::restoreTranslationLink().
     *
     * @param  array<int, int>  $postIds
     * @return array<string, mixed>
     */
    public static function translationLinkBefore(array $postIds): array
    {
        if (! function_exists('pll_get_post_translations')) {
            return ['before' => []];
        }

        $before = [];
        $seen = [];
        foreach ($postIds as $postId) {
            $group = pll_get_post_translations((int) $postId);
            $members = array_merge([(int) $postId], array_map('intval', array_values($group)));
            foreach ($members as $member) {
                if ($member <= 0 || isset($seen[$member])) {
                    continue;
                }
                $seen[$member] = true;
                $before[] = [
                    'id' => $member,
                    'lang' => pll_get_post_language($member) ?: '',
                    'group' => array_map('intval', pll_get_post_translations($member)),
                ];
            }
        }

        return ['before' => $before];
    }

    /**
     * Term relationships keyed by taxonomy: [taxonomy => [term_id, ...]].
     *
     * @return array<string, mixed>
     */
    public static function postTerms(int $id): array
    {
        $out = [];
        foreach (get_object_taxonomies(get_post_type($id) ?: 'post') as $tax) {
            $ids = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
            if (! is_wp_error($ids) && $ids) {
                $out[$tax] = array_map('intval', $ids);
            }
        }

        return $out;
    }

    /**
     * Term core fields + meta, to restore an updated term (id stays stable).
     *
     * @return array<string, mixed>
     */
    public static function termFields(int $termId, string $taxonomy): array
    {
        $term = get_term($termId, $taxonomy);
        if (! $term || is_wp_error($term)) {
            return [];
        }

        return [
            'term_id' => $termId,
            'taxonomy' => $taxonomy,
            'fields' => [
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'parent' => $term->parent,
            ],
            'meta' => get_term_meta($termId),
        ];
    }

    /**
     * Everything needed to recreate a deleted term under its ORIGINAL id and
     * re-attach it to the posts that had it. Capturing the term_taxonomy_id
     * lets the restore re-insert with the same ids, so existing references
     * (menus, ACF term fields, query blocks) keep working — see
     * {@see RestoreSnapshot::recreateTerm()}.
     *
     * @return array<string, mixed>
     */
    public static function termForRecreate(int $termId, string $taxonomy): array
    {
        $term = get_term($termId, $taxonomy);
        if (! $term || is_wp_error($term)) {
            return [];
        }

        $data = self::termFields($termId, $taxonomy);
        $data['term_taxonomy_id'] = (int) $term->term_taxonomy_id;
        $data['term_group'] = (int) $term->term_group;
        $data['object_ids'] = array_map('intval', (array) (get_objects_in_term($termId, $taxonomy) ?: []));

        return $data;
    }
}
