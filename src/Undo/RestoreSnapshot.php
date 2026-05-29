<?php

namespace GeneroWP\MCP\Undo;

use WP_Error;

/**
 * Replays a {@see Snapshot} to undo a previous write.
 *
 * Registered on the `gds-mcp/restore_snapshot` filter so gds-assistant can
 * trigger an undo without the restore path being exposed to the LLM as a
 * tool. Restores via low-level WP/GF/Polylang APIs (not the guarded abilities)
 * so it can't recurse into the destructive-write guards — an undo is a
 * deliberate, already-confirmed action.
 *
 * Returns an array summary on success or a WP_Error if the target no longer
 * exists (best-effort: the world may have moved on since the snapshot).
 */
final class RestoreSnapshot
{
    public static function register(): void
    {
        add_filter('gds-mcp/restore_snapshot', [self::class, 'handle'], 10, 2);
    }

    /**
     * @param  mixed  $default  Filter default (ignored).
     * @param  array  $snapshot  ['kind' => string, 'data' => array, 'label' => string]
     */
    public static function handle(mixed $default, array $snapshot): array|WP_Error
    {
        $kind = (string) ($snapshot['kind'] ?? '');
        $data = (array) ($snapshot['data'] ?? []);

        return match ($kind) {
            'restore-post' => self::restorePost($data),
            'restore-post-partial' => self::restorePostPartial($data),
            'untrash' => self::untrash($data),
            'trash' => self::trash($data),
            'restore-term' => self::restoreTerm($data),
            'delete-term' => self::deleteTerm($data),
            'recreate-term' => self::recreateTerm($data),
            'restore-form' => self::restoreForm($data),
            'delete-form' => self::deleteForm($data),
            'restore-feed' => self::restoreFeed($data),
            'delete-feed' => self::deleteFeed($data),
            'recreate-feed' => self::recreateFeed($data),
            'restore-redirect' => self::restoreRedirect($data),
            'restore-translation-link' => self::restoreTranslationLink($data),
            'restore-string' => self::restoreString($data),
            'bulk' => self::bulk($data),
            default => new WP_Error('unknown_undo_kind', "Don't know how to undo '{$kind}'."),
        };
    }

    private static function restorePost(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! get_post($id)) {
            return new WP_Error('restore_failed', "Post {$id} no longer exists.");
        }

        $postArr = (array) ($data['post'] ?? []);
        $postArr['ID'] = $id;
        $result = wp_update_post($postArr, true);
        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($data['meta']) && is_array($data['meta'])) {
            foreach (array_keys(get_post_meta($id)) as $key) {
                delete_post_meta($id, $key);
            }
            foreach ($data['meta'] as $key => $values) {
                foreach ((array) $values as $value) {
                    add_post_meta($id, $key, maybe_unserialize($value));
                }
            }
        }

        if (isset($data['terms']) && is_array($data['terms'])) {
            foreach ($data['terms'] as $taxonomy => $ids) {
                wp_set_object_terms($id, array_map('intval', (array) $ids), $taxonomy, false);
            }
        }

        return ['restored' => 'post', 'id' => $id];
    }

    /**
     * Merge-restore: only the post_status and the specific meta keys that were
     * changed are reverted — untouched meta is left alone. Used by bulk-update,
     * which may touch hundreds of posts (so we never snapshot full content/meta)
     * and must not clobber meta it didn't write.
     *
     * data: { id, status?, meta_prev: { key => [values]|null } }
     */
    private static function restorePostPartial(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! get_post($id)) {
            return new WP_Error('restore_failed', "Post {$id} no longer exists.");
        }

        if (! empty($data['status'])) {
            wp_update_post(['ID' => $id, 'post_status' => (string) $data['status']]);
        }

        foreach ((array) ($data['meta_prev'] ?? []) as $key => $values) {
            delete_post_meta($id, $key);
            if ($values !== null) {
                // Values were captured via get_post_meta($id,$key) (already
                // unserialized); add_post_meta re-serializes as needed.
                foreach ((array) $values as $value) {
                    add_post_meta($id, $key, $value);
                }
            }
        }

        return ['restored' => 'post', 'id' => $id];
    }

    private static function untrash(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! get_post($id)) {
            return new WP_Error('restore_failed', "Post {$id} no longer exists to untrash.");
        }
        if (get_post_status($id) !== 'trash') {
            return ['restored' => 'post', 'id' => $id, 'note' => 'Already not in trash.'];
        }
        if (! wp_untrash_post($id)) {
            return new WP_Error('restore_failed', "Failed to untrash post {$id}.");
        }

        return ['restored' => 'untrash', 'id' => $id];
    }

    private static function trash(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! get_post($id)) {
            return ['restored' => 'trash', 'id' => $id, 'note' => 'Already removed.'];
        }
        if (! wp_trash_post($id)) {
            return new WP_Error('restore_failed', "Failed to remove post {$id}.");
        }

        return ['restored' => 'trash', 'id' => $id];
    }

    private static function restoreTerm(array $data): array|WP_Error
    {
        $termId = (int) ($data['term_id'] ?? 0);
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        if (! $termId || ! term_exists($termId, $taxonomy)) {
            return new WP_Error('restore_failed', "Term {$termId} no longer exists.");
        }

        $result = wp_update_term($termId, $taxonomy, (array) ($data['fields'] ?? []));
        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($data['meta']) && is_array($data['meta'])) {
            foreach (array_keys(get_term_meta($termId)) as $key) {
                delete_term_meta($termId, $key);
            }
            foreach ($data['meta'] as $key => $values) {
                foreach ((array) $values as $value) {
                    add_term_meta($termId, $key, maybe_unserialize($value));
                }
            }
        }

        return ['restored' => 'term', 'term_id' => $termId];
    }

    private static function deleteTerm(array $data): array|WP_Error
    {
        $termId = (int) ($data['term_id'] ?? 0);
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        if ($termId && term_exists($termId, $taxonomy)) {
            wp_delete_term($termId, $taxonomy);
        }

        return ['restored' => 'delete-term', 'term_id' => $termId];
    }

    /**
     * Recreate a deleted term. Restores it under its ORIGINAL term_id and
     * term_taxonomy_id when those are still free, so every reference that
     * stored the id (menu items, ACF fields, query blocks) keeps working — a
     * faithful undo with nothing to rewrite. Only if the id was reused since
     * the delete do we fall back to a new id and report the references that
     * still point at the old one (rather than blindly rewriting them).
     */
    private static function recreateTerm(array $data): array|WP_Error
    {
        global $wpdb;

        $taxonomy = (string) ($data['taxonomy'] ?? '');
        $fields = (array) ($data['fields'] ?? []);
        $name = (string) ($fields['name'] ?? '');
        $oldId = (int) ($data['term_id'] ?? 0);
        $ttId = (int) ($data['term_taxonomy_id'] ?? 0);

        if ($name === '' || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('restore_failed', 'Cannot recreate term: missing name or taxonomy.');
        }

        $idReused = $oldId && (bool) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d", $oldId));
        $ttReused = $ttId && (bool) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId));

        // Preferred path: re-insert with the original ids.
        if ($oldId && $ttId && ! $idReused && ! $ttReused) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($wpdb->terms, [
                'term_id' => $oldId,
                'name' => $name,
                'slug' => (string) ($fields['slug'] ?? sanitize_title($name)),
                'term_group' => (int) ($data['term_group'] ?? 0),
            ]);
            $wpdb->insert($wpdb->term_taxonomy, [
                'term_taxonomy_id' => $ttId,
                'term_id' => $oldId,
                'taxonomy' => $taxonomy,
                'description' => (string) ($fields['description'] ?? ''),
                'parent' => (int) ($fields['parent'] ?? 0),
                'count' => 0,
            ]);
            clean_term_cache([$oldId], $taxonomy);

            self::restoreTermMeta($oldId, (array) ($data['meta'] ?? []));
            self::reattachObjects($ttId, (array) ($data['object_ids'] ?? []));
            wp_update_term_count_now([$ttId], $taxonomy);

            return [
                'restored' => 'recreate-term',
                'term_id' => $oldId,
                'note' => 'Term restored under its original id; existing references remain valid.',
            ];
        }

        // Fallback: the id was reused, so we must create a new one. Don't
        // rewrite references blindly — report what still points at the old id.
        $created = wp_insert_term($name, $taxonomy, [
            'slug' => $fields['slug'] ?? '',
            'description' => $fields['description'] ?? '',
            'parent' => (int) ($fields['parent'] ?? 0),
        ]);
        if (is_wp_error($created)) {
            return $created;
        }
        $newId = (int) $created['term_id'];

        self::restoreTermMeta($newId, (array) ($data['meta'] ?? []));
        foreach ((array) ($data['object_ids'] ?? []) as $objectId) {
            wp_set_object_terms((int) $objectId, [$newId], $taxonomy, true);
        }

        return [
            'restored' => 'recreate-term',
            'term_id' => $newId,
            'old_term_id' => $oldId,
            'caveats' => self::staleTermReferences($oldId, $newId),
        ];
    }

    /**
     * Re-link objects to a term by inserting term_relationships rows directly.
     * Needed because the term was re-inserted under its original id via raw
     * SQL, and wp_set_object_terms() SKIPS integer term ids that term_exists()
     * can't yet resolve for a freshly raw-inserted term.
     */
    private static function reattachObjects(int $ttId, array $objectIds): void
    {
        global $wpdb;
        foreach ($objectIds as $objectId) {
            $objectId = (int) $objectId;
            if (! $objectId) {
                continue;
            }
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d",
                $objectId,
                $ttId,
            ));
            if (! $exists) {
                $wpdb->insert($wpdb->term_relationships, [
                    'object_id' => $objectId,
                    'term_taxonomy_id' => $ttId,
                    'term_order' => 0,
                ]);
            }
            clean_object_term_cache($objectId, get_post_type($objectId) ?: 'post');
        }
    }

    private static function restoreTermMeta(int $termId, array $meta): void
    {
        foreach (array_keys(get_term_meta($termId)) as $key) {
            delete_term_meta($termId, $key);
        }
        foreach ($meta as $key => $values) {
            foreach ((array) $values as $value) {
                add_term_meta($termId, $key, maybe_unserialize($value));
            }
        }
    }

    /**
     * Report (don't rewrite) places that still reference the old term id, so
     * the assistant can warn the user. Blindly rewriting by numeric match
     * could corrupt unrelated data, so this only surfaces what to check.
     *
     * @return string[]
     */
    private static function staleTermReferences(int $oldId, int $newId): array
    {
        global $wpdb;
        $caveats = [
            sprintf('The term was reinstated under a NEW id (%d; was %d) because the old id had been reused. References below still point at %d:', $newId, $oldId, $oldId),
        ];

        $menuItems = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object_id' AND meta_value = %d",
            $oldId,
        ));
        if ($menuItems) {
            $caveats[] = "{$menuItems} nav menu item(s) (menu links to the old term archive).";
        }

        $meta = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value = %d AND meta_key NOT LIKE '\\_%'",
            $oldId,
        ));
        if ($meta) {
            $caveats[] = "{$meta} custom field value(s) equal to the old id (e.g. ACF term fields) — verify before changing.";
        }

        $caveats[] = 'Block content (query loops, term lists) may also embed the old id; search content if the term is used in blocks.';

        return $caveats;
    }

    private static function restoreForm(array $data): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }
        $id = (int) ($data['id'] ?? 0);
        $form = (array) ($data['form'] ?? []);
        if (! $id || ! $form) {
            return new WP_Error('restore_failed', 'Missing form snapshot.');
        }
        // GFAPI::update_form returns false on validation/save failure without
        // raising a WP_Error — checking only is_wp_error() lets a silent
        // failure flow through as "Undone", which is what surfaced the bug
        // "undo said done but the field didn't change". Treat false as an
        // explicit failure so we don't lie to the user.
        $result = \GFAPI::update_form($form, $id);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === false) {
            return new WP_Error(
                'restore_failed',
                "Failed to restore form {$id}: GFAPI::update_form returned false (validation rejected or save failed).",
            );
        }

        return ['restored' => 'form', 'id' => $id];
    }

    private static function deleteForm(array $data): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id && \GFAPI::get_form($id)) {
            \GFAPI::delete_form($id);
        }

        return ['restored' => 'delete-form', 'id' => $id];
    }

    private static function restoreFeed(array $data): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }
        $feedId = (int) ($data['feed_id'] ?? 0);
        $meta = (array) ($data['meta'] ?? []);
        if (! $feedId || ! $meta) {
            return new WP_Error('restore_failed', 'Missing feed snapshot.');
        }
        $result = \GFAPI::update_feed($feedId, $meta);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === false) {
            return new WP_Error(
                'restore_failed',
                "Failed to restore feed {$feedId}: GFAPI::update_feed returned false.",
            );
        }

        // update_feed only restores meta; the form binding / active state /
        // order are separate properties that the edit could also have changed.
        self::restoreFeedProperties($feedId, $data);

        return ['restored' => 'feed', 'feed_id' => $feedId];
    }

    /**
     * Recreate a deleted feed. GF has no re-insert-with-id, so add_feed mints a
     * NEW feed_id — fine for the feed itself, but anything keyed on the old
     * feed id (rare) won't reconnect.
     *
     * data: { form_id, addon_slug, meta, is_active?, feed_order? }
     */
    private static function recreateFeed(array $data): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }
        $formId = (int) ($data['form_id'] ?? 0);
        $addon = (string) ($data['addon_slug'] ?? '');
        $meta = (array) ($data['meta'] ?? []);
        if (! $formId || ! $addon || ! $meta) {
            return new WP_Error('restore_failed', 'Missing feed snapshot.');
        }

        $newId = \GFAPI::add_feed($formId, $meta, $addon);
        if (is_wp_error($newId)) {
            return $newId;
        }
        self::restoreFeedProperties((int) $newId, $data);

        return [
            'restored' => 'recreate-feed',
            'feed_id' => (int) $newId,
            'note' => 'Feed restored under a new id; any reference to the old feed id (uncommon) will not reconnect.',
        ];
    }

    private static function restoreFeedProperties(int $feedId, array $data): void
    {
        if (array_key_exists('form_id', $data)) {
            \GFAPI::update_feed_property($feedId, 'form_id', (int) $data['form_id']);
        }
        if (array_key_exists('is_active', $data)) {
            \GFAPI::update_feed_property($feedId, 'is_active', (int) $data['is_active']);
        }
        if (array_key_exists('feed_order', $data)) {
            \GFAPI::update_feed_property($feedId, 'feed_order', (int) $data['feed_order']);
        }
    }

    /**
     * Undo a created redirect. Provider-specific (the create path records which
     * provider ran).
     *
     * data: { provider: 'srm'|'redirection'|'yoast', ... }
     *   srm:         { post_id }
     *   redirection: { item_id }
     *   yoast:       { post_id, prev_meta: string|null }
     */
    private static function restoreRedirect(array $data): array|WP_Error
    {
        $provider = (string) ($data['provider'] ?? '');

        return match ($provider) {
            'srm' => self::trash(['id' => (int) ($data['post_id'] ?? 0)]),
            'redirection' => self::deleteRedirectionItem((int) ($data['item_id'] ?? 0)),
            'yoast' => self::restoreYoastRedirect($data),
            default => new WP_Error('restore_failed', "Unknown redirect provider '{$provider}'."),
        };
    }

    private static function deleteRedirectionItem(int $itemId): array|WP_Error
    {
        if (! class_exists('Red_Item') || ! $itemId) {
            return new WP_Error('restore_failed', 'Redirection item not found.');
        }
        $item = \Red_Item::get_by_id($itemId);
        if ($item) {
            $item->delete();
        }

        return ['restored' => 'delete-redirect', 'item_id' => $itemId];
    }

    private static function restoreYoastRedirect(array $data): array|WP_Error
    {
        $postId = (int) ($data['post_id'] ?? 0);
        if (! $postId) {
            return new WP_Error('restore_failed', 'Missing post for Yoast redirect.');
        }
        $prev = $data['prev_meta'] ?? null;
        if ($prev === null || $prev === '') {
            delete_post_meta($postId, '_yoast_wpseo_redirect');
        } else {
            update_post_meta($postId, '_yoast_wpseo_redirect', $prev);
        }

        return ['restored' => 'restore-redirect', 'post_id' => $postId];
    }

    private static function deleteFeed(array $data): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }
        $feedId = (int) ($data['feed_id'] ?? 0);
        if ($feedId) {
            \GFAPI::delete_feed($feedId);
        }

        return ['restored' => 'delete-feed', 'feed_id' => $feedId];
    }

    /**
     * Re-apply each affected post's prior language and translation group.
     */
    private static function restoreTranslationLink(array $data): array|WP_Error
    {
        if (! function_exists('pll_save_post_translations')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $groups = [];
        foreach ((array) ($data['before'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $lang = (string) ($entry['lang'] ?? '');
            if ($id && $lang) {
                pll_set_post_language($id, $lang);
            }
            $group = array_map('intval', (array) ($entry['group'] ?? []));
            if (count($group) > 1) {
                ksort($group);
                $groups[implode(',', $group)] = $group;
            }
        }
        foreach ($groups as $group) {
            pll_save_post_translations($group);
        }

        return ['restored' => 'translation-link', 'count' => count($data['before'] ?? [])];
    }

    private static function restoreString(array $data): array|WP_Error
    {
        if (! class_exists('PLL_MO') || ! function_exists('PLL')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }
        $string = (string) ($data['string'] ?? '');
        $lang = (string) ($data['lang'] ?? '');
        $translation = (string) ($data['translation'] ?? '');
        $langObject = PLL()->model->get_language($lang);
        if ($string === '' || ! $langObject) {
            return new WP_Error('restore_failed', 'Missing string or language.');
        }

        $mo = new \PLL_MO;
        $mo->import_from_db($langObject);
        $mo->add_entry($mo->make_entry($string, $translation));
        $mo->export_to_db($langObject);

        return ['restored' => 'string', 'string' => $string, 'lang' => $lang];
    }

    /**
     * Replay a list of sub-snapshots. Each item is itself a {kind, data}
     * snapshot, so a bulk undo can mix kinds (e.g. restore-post-partial per
     * post for bulk-update, or delete-feed per feed for feeds-duplicate).
     *
     * data: { items: [ {kind, data}, ... ] }
     */
    private static function bulk(array $data): array|WP_Error
    {
        $restored = 0;
        $errors = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (! is_array($item) || empty($item['kind'])) {
                continue;
            }
            $result = self::handle(null, $item);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $restored++;
            }
        }

        return ['restored' => 'bulk', 'count' => $restored, 'errors' => $errors];
    }
}
