<?php

namespace GeneroWP\MCP\Undo;

use GeneroWP\MCP\Abilities\NavMenuItemsAbility;
use GeneroWP\MCP\Integrations\Yoast\SeoFields;
use GeneroWP\MCP\Undo\Snapshots\PartialPostSnapshot;
use GeneroWP\MCP\Undo\Snapshots\PostFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermForRecreateSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TranslationLinkBeforeSnapshot;
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
 *
 * @phpstan-type RestoreSummary array<string, mixed>
 */
final class RestoreSnapshot
{
    /**
     * Meta WordPress writes about a post's own history (old URLs, edit locks).
     * A restore neither rewinds nor removes it, and a copy never inherits it.
     */
    public const BOOKKEEPING_META = ['_wp_old_slug', '_wp_old_date', '_edit_lock', '_edit_last'];

    public static function register(): void
    {
        add_filter('gds-mcp/restore_snapshot', [self::class, 'handle'], 10, 2);
    }

    /**
     * @param  mixed  $default  Filter default (ignored).
     * @param  array  $snapshot  ['kind' => string, 'data' => array, 'label' => string]
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|WP_Error
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
            'recreate-menu-item' => self::recreateMenuItem($data),
            'restore-term' => self::restoreTerm($data),
            'delete-term' => self::deleteTerm($data),
            'delete-attachment' => self::deleteAttachment($data),
            'recreate-term' => self::recreateTerm($data),
            'restore-form' => self::restoreForm($data),
            'delete-form' => self::deleteForm($data),
            'restore-feed' => self::restoreFeed($data),
            'delete-feed' => self::deleteFeed($data),
            'recreate-feed' => self::recreateFeed($data),
            'restore-redirect' => self::restoreRedirect($data),
            'restore-term-seo' => self::restoreTermSeo($data),
            'restore-translation-link' => self::restoreTranslationLink($data),
            'restore-string' => self::restoreString($data),
            'bulk' => self::bulk($data),
            default => new WP_Error('unknown_undo_kind', "Don't know how to undo '{$kind}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function restorePost(array $data): array|WP_Error
    {
        $snapshot = PostFieldsSnapshot::fromArray($data);
        if (! $snapshot) {
            return new WP_Error('restore_failed', 'Snapshot was missing the post id.');
        }
        if (! get_post($snapshot->id)) {
            return new WP_Error('restore_failed', "Post {$snapshot->id} no longer exists.");
        }

        // wp_update_post()/add_post_meta() unslash their input; the snapshot
        // holds raw DB values, so slash them or backslashes are stripped.
        $result = wp_update_post(wp_slash(['ID' => $snapshot->id] + $snapshot->post), true);
        if (is_wp_error($result)) {
            return $result;
        }

        // Only touch keys whose values differ, so meta written by others
        // since the snapshot (and meta ids other code relies on) survive.
        // WordPress's own bookkeeping is left as it is: the update above may
        // just have recorded the slug being left in _wp_old_slug, which keeps
        // the old URL redirecting.
        $current = array_diff_key(get_post_meta($snapshot->id), array_flip(self::BOOKKEEPING_META));
        foreach (array_diff_key($snapshot->meta, array_flip(self::BOOKKEEPING_META)) as $key => $values) {
            if (array_values($current[$key] ?? []) === $values) {
                continue;
            }
            delete_post_meta($snapshot->id, $key);
            foreach ($values as $value) {
                add_post_meta($snapshot->id, $key, wp_slash(maybe_unserialize($value)));
            }
        }
        // A key added since the snapshot is removed only when the snapshot
        // says it holds every key; older snapshots don't, so leave those.
        if ($snapshot->metaComplete) {
            foreach (array_diff_key($current, $snapshot->meta) as $key => $values) {
                delete_post_meta($snapshot->id, (string) $key);
            }
        }

        // Older snapshots may still hold the translation group; never restore
        // it. The language goes back through Polylang, which keeps its
        // caches and the translation group consistent.
        $terms = array_diff_key($snapshot->terms, array_flip(PostFieldsSnapshot::TRANSLATION_GROUP_TAXONOMIES));
        if (isset($terms['language']) && function_exists('pll_set_post_language')) {
            $language = $terms['language'] ? get_term((int) $terms['language'][0], 'language') : null;
            if ($language instanceof \WP_Term && pll_get_post_language($snapshot->id) !== $language->slug) {
                pll_set_post_language($snapshot->id, $language->slug);
            }
            unset($terms['language']);
        }
        foreach ($terms as $taxonomy => $ids) {
            wp_set_object_terms($snapshot->id, $ids, $taxonomy, false);
        }

        return ['restored' => 'post', 'id' => $snapshot->id];
    }

    /**
     * Merge-restore: only the post_status and the specific meta keys that were
     * changed are reverted — untouched meta is left alone. Used by bulk-update,
     * which may touch hundreds of posts (so we never snapshot full content/meta)
     * and must not clobber meta it didn't write.
     *
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function restorePostPartial(array $data): array|WP_Error
    {
        $snapshot = PartialPostSnapshot::fromArray($data);
        if (! $snapshot) {
            return new WP_Error('restore_failed', 'Snapshot was missing the post id.');
        }
        if (! get_post($snapshot->id)) {
            return new WP_Error('restore_failed', "Post {$snapshot->id} no longer exists.");
        }

        if ($snapshot->status !== '') {
            wp_update_post(['ID' => $snapshot->id, 'post_status' => $snapshot->status]);
        }

        foreach ($snapshot->metaPrev as $key => $values) {
            delete_post_meta($snapshot->id, $key);
            if ($values === null) {
                continue;
            }
            // Values were captured via get_post_meta($id,$key) (already
            // unserialized); add_post_meta re-serializes as needed but also
            // unslashes, hence wp_slash().
            foreach ($values as $value) {
                add_post_meta($snapshot->id, $key, wp_slash($value));
            }
        }

        return ['restored' => 'post', 'id' => $snapshot->id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private static function untrash(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! get_post($id)) {
            return new WP_Error('restore_failed', "Post {$id} no longer exists to untrash.");
        }
        if (get_post_status($id) !== 'trash') {
            return ['restored' => 'post', 'id' => $id, 'note' => 'Already not in trash.'];
        }

        // Since WP 5.6 untrashing gives a draft. Put back the status the post
        // had when it was trashed: the one the undo row recorded, or for rows
        // without it the one core kept in _wp_trash_meta_status.
        $status = (string) ($data['status'] ?? '');
        $priorStatus = static fn ($newStatus, $postId, $previousStatus) => (int) $postId === $id
            ? ($status !== '' ? $status : ((string) $previousStatus ?: $newStatus))
            : $newStatus;
        add_filter('wp_untrash_post_status', $priorStatus, 10, 3);
        $untrashed = wp_untrash_post($id);
        remove_filter('wp_untrash_post_status', $priorStatus, 10);

        if (! $untrashed) {
            return new WP_Error('restore_failed', "Failed to untrash post {$id}.");
        }

        return ['restored' => 'untrash', 'id' => $id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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

    /**
     * Recreate a deleted menu item (they have no trash), under its original id
     * when that is still free. Its former sub-items are pointed back at it, and
     * the gap the delete closed is reopened against the menu as it is now, so
     * items added or moved since keep their places relative to each other.
     *
     * data: { item: PostFieldsSnapshot, children: list<int> }
     *
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function recreateMenuItem(array $data): array|WP_Error
    {
        $snapshot = PostFieldsSnapshot::fromArray((array) ($data['item'] ?? []));
        if (! $snapshot) {
            return new WP_Error('restore_failed', 'Snapshot was missing the menu item id.');
        }

        $menuId = (int) ($snapshot->terms['nav_menu'][0] ?? 0);
        if (! $menuId || ! term_exists($menuId, 'nav_menu')) {
            return new WP_Error('restore_failed', 'The menu this item belonged to no longer exists.');
        }

        // Back between the items it sat between: right before the one that
        // followed it, else right after the one before it, else (both gone,
        // or a row from before neighbours were recorded) at its old order.
        $inMenu = static fn (int $itemId): ?\WP_Post => $itemId && has_term($menuId, 'nav_menu', $itemId) ? get_post($itemId) : null;
        $after = $inMenu((int) ($data['after'] ?? 0));
        $before = $inMenu((int) ($data['before'] ?? 0));
        $order = $after ? (int) $after->menu_order : ($before ? (int) $before->menu_order + 1 : (int) $snapshot->post['menu_order']);
        NavMenuItemsAbility::shiftOrder($menuId, $order, +1);

        // wp_insert_post() keeps import_id only when no post has that id.
        $fields = ['menu_order' => $order] + $snapshot->post;
        $newId = wp_insert_post(wp_slash(['import_id' => $snapshot->id, 'post_type' => 'nav_menu_item'] + $fields), true);
        if (is_wp_error($newId)) {
            NavMenuItemsAbility::shiftOrder($menuId, $order + 1, -1);

            return $newId;
        }

        $restored = self::restorePost((new PostFieldsSnapshot($newId, $fields, $snapshot->meta, $snapshot->terms, true))->toArray());
        if (is_wp_error($restored)) {
            // All or nothing: no half-restored item left in the menu.
            wp_delete_post($newId, true);
            NavMenuItemsAbility::shiftOrder($menuId, $order + 1, -1);

            return $restored;
        }

        foreach ((array) ($data['children'] ?? []) as $childId) {
            if (get_post_type((int) $childId) === 'nav_menu_item') {
                update_post_meta((int) $childId, '_menu_item_menu_item_parent', (string) $newId);
            }
        }

        $summary = ['restored' => 'recreate-menu-item', 'id' => $newId];
        if ($newId !== $snapshot->id) {
            $summary['caveats'] = ["The menu item came back under a new id ({$newId}; was {$snapshot->id}) because the old id had been reused."];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function restoreTerm(array $data): array|WP_Error
    {
        $snapshot = TermFieldsSnapshot::fromArray($data);
        if (! $snapshot) {
            return new WP_Error('restore_failed', 'Snapshot was missing term_id or taxonomy.');
        }
        if (! term_exists($snapshot->termId, $snapshot->taxonomy)) {
            return new WP_Error('restore_failed', "Term {$snapshot->termId} no longer exists.");
        }

        $result = wp_update_term($snapshot->termId, $snapshot->taxonomy, wp_slash($snapshot->fields));
        if (is_wp_error($result)) {
            return $result;
        }

        foreach (array_keys(get_term_meta($snapshot->termId)) as $key) {
            delete_term_meta($snapshot->termId, $key);
        }
        foreach ($snapshot->meta as $key => $values) {
            foreach ($values as $value) {
                add_term_meta($snapshot->termId, $key, wp_slash(maybe_unserialize($value)));
            }
        }

        return ['restored' => 'term', 'term_id' => $snapshot->termId];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function deleteTerm(array $data): array
    {
        $termId = (int) ($data['term_id'] ?? 0);
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        if ($termId && term_exists($termId, $taxonomy)) {
            wp_delete_term($termId, $taxonomy);
        }

        return ['restored' => 'delete-term', 'term_id' => $termId];
    }

    /**
     * Undo an upload: delete the attachment and its files for good.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private static function deleteAttachment(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || get_post_type($id) !== 'attachment') {
            return ['restored' => 'delete-attachment', 'id' => $id, 'note' => 'Already removed.'];
        }
        // Since the upload it may have become a featured image, a gallery
        // image, the site logo, or been put in content; deleting the file
        // would break those, so leave it.
        $usedBy = self::attachmentUsers($id);
        if (is_wp_error($usedBy)) {
            return $usedBy;
        }
        if ($usedBy) {
            return new WP_Error('restore_failed', sprintf('The uploaded file is now used by %s, so it was not deleted. Remove it there first, or delete it in the Media Library.', implode(', ', $usedBy)));
        }
        if (! wp_delete_attachment($id, true)) {
            return new WP_Error('restore_failed', "Failed to delete attachment {$id}.");
        }

        return ['restored' => 'delete-attachment', 'id' => $id];
    }

    /**
     * What uses an attachment: post or term meta holding its id (featured
     * image, term thumbnail, a comma-separated list like
     * _product_image_gallery, a serialized array like an ACF gallery), the
     * site logo or icon, or post content (its wp-image-{id} class, or its
     * URL, size variants included). An error when a lookup failed: not
     * knowing is no reason to delete.
     *
     * @return list<string>|WP_Error
     */
    private static function attachmentUsers(int $id): array|WP_Error
    {
        global $wpdb;

        $users = [];
        foreach (['site_logo', 'site_icon'] as $option) {
            if ((int) get_option($option) === $id) {
                $users[] = "the {$option} setting";
            }
        }
        if ((int) get_theme_mod('custom_logo') === $id) {
            $users[] = 'the custom_logo theme setting';
        }

        // The id alone, in a comma-separated list, or as a serialized string.
        $metaMatch = '(meta_value = %s OR meta_value REGEXP %s OR meta_value LIKE %s)';
        $metaArgs = [(string) $id, '(^|,)'.$id.'(,|$)', '%'.$wpdb->esc_like('"'.$id.'";').'%'];

        $url = (string) wp_get_attachment_url($id);
        // The URL with or without a -300x200 size suffix, then its extension:
        // `photo` must not match `photo-2.png`.
        $urlPattern = $url !== '' ? preg_quote((string) preg_replace('/\.[^.\/]+$/', '', $url), '/').'(-[0-9]+x[0-9]+)?\\.' : '';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lookups = [
            // Its own rows aside, and _edit_last, which holds a user id.
            ['post', $wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key != '_edit_last' AND {$metaMatch}", $id, ...$metaArgs)],
            ['term', $wpdb->prepare("SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE {$metaMatch}", ...$metaArgs)],
            ['post', $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type NOT IN ('revision', 'attachment') AND post_status != 'trash'
                 AND (post_content REGEXP %s OR (%s != '' AND post_content REGEXP %s))",
                'wp-image-'.$id.'([^0-9]|$)',
                $urlPattern,
                $urlPattern
            )],
        ];
        foreach ($lookups as [$kind, $query]) {
            $found = $wpdb->get_col($query);
            if ($wpdb->last_error !== '') {
                return new WP_Error('restore_failed', 'Could not check what uses the uploaded file, so it was not deleted.');
            }
            foreach ($found as $objectId) {
                $users[] = "{$kind} {$objectId}";
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_values(array_unique($users));
    }

    /**
     * Recreate a deleted term. Restores it under its ORIGINAL term_id and
     * term_taxonomy_id when those are still free, so every reference that
     * stored the id (menu items, ACF fields, query blocks) keeps working — a
     * faithful undo with nothing to rewrite. Only if the id was reused since
     * the delete do we fall back to a new id and report the references that
     * still point at the old one (rather than blindly rewriting them).
     *
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function recreateTerm(array $data): array|WP_Error
    {
        global $wpdb;

        $snapshot = TermForRecreateSnapshot::fromArray($data);
        if (! $snapshot) {
            return new WP_Error('restore_failed', 'Cannot recreate term: snapshot is missing term_id or taxonomy.');
        }
        if ($snapshot->fields['name'] === '' || ! taxonomy_exists($snapshot->taxonomy)) {
            return new WP_Error('restore_failed', 'Cannot recreate term: missing name or taxonomy.');
        }

        $oldId = $snapshot->termId;
        $ttId = $snapshot->termTaxonomyId;
        $idReused = $oldId && (bool) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d", $oldId));
        $ttReused = $ttId && (bool) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId));

        // Preferred path: re-insert with the original ids.
        if ($oldId && $ttId && ! $idReused && ! $ttReused) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($wpdb->terms, [
                'term_id' => $oldId,
                'name' => $snapshot->fields['name'],
                'slug' => $snapshot->fields['slug'] !== '' ? $snapshot->fields['slug'] : sanitize_title($snapshot->fields['name']),
                'term_group' => $snapshot->termGroup,
            ]);
            $wpdb->insert($wpdb->term_taxonomy, [
                'term_taxonomy_id' => $ttId,
                'term_id' => $oldId,
                'taxonomy' => $snapshot->taxonomy,
                'description' => $snapshot->fields['description'],
                'parent' => $snapshot->fields['parent'],
                'count' => 0,
            ]);
            clean_term_cache([$oldId], $snapshot->taxonomy);

            self::restoreTermMeta($oldId, $snapshot->meta);
            self::reattachObjects($ttId, $snapshot->objectIds);
            wp_update_term_count_now([$ttId], $snapshot->taxonomy);

            return [
                'restored' => 'recreate-term',
                'term_id' => $oldId,
                'note' => 'Term restored under its original id; existing references remain valid.',
            ];
        }

        // Fallback: the id was reused, so we must create a new one. Don't
        // rewrite references blindly — report what still points at the old id.
        $created = wp_insert_term($snapshot->fields['name'], $snapshot->taxonomy, [
            'slug' => $snapshot->fields['slug'],
            'description' => $snapshot->fields['description'],
            'parent' => $snapshot->fields['parent'],
        ]);
        if (is_wp_error($created)) {
            return $created;
        }
        $newId = (int) $created['term_id'];

        self::restoreTermMeta($newId, $snapshot->meta);
        foreach ($snapshot->objectIds as $objectId) {
            wp_set_object_terms($objectId, [$newId], $snapshot->taxonomy, true);
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
     *
     * @param  list<int>  $objectIds
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

    /**
     * @param  array<string, list<string>>  $meta
     */
    private static function restoreTermMeta(int $termId, array $meta): void
    {
        foreach (array_keys(get_term_meta($termId)) as $key) {
            delete_term_meta($termId, $key);
        }
        foreach ($meta as $key => $values) {
            foreach ((array) $values as $value) {
                add_term_meta($termId, $key, wp_slash(maybe_unserialize($value)));
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
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

    /**
     * @param  array<string, mixed>  $data
     */
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
     * Puts a term's Yoast SEO entry back as captured by gds/seo-update.
     *
     * @param  array<string, mixed>  $data  ['term_id', 'taxonomy', 'prev' => key → prior value|null]
     * @return RestoreSummary|WP_Error
     */
    private static function restoreTermSeo(array $data): array|WP_Error
    {
        $termId = (int) ($data['term_id'] ?? 0);
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        if ($termId <= 0 || $taxonomy === '') {
            return new WP_Error('restore_failed', 'Snapshot was missing term_id or taxonomy.');
        }
        $term = get_term($termId, $taxonomy);
        if (! $term instanceof \WP_Term) {
            return new WP_Error('restore_failed', "Term {$termId} no longer exists.");
        }

        SeoFields::restoreTermValues($term, (array) ($data['prev'] ?? []));

        return ['restored' => 'term-seo', 'term_id' => $termId];
    }

    /**
     * Undo a created redirect. Provider-specific (the create path records which
     * provider ran).
     *
     * data: { provider: 'srm'|'redirection'|'yoast', ... }
     *   srm:         { post_id }
     *   redirection: { item_id }
     *   yoast:       { post_id, prev_meta: string|null }
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
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

    /**
     * @return array<string, mixed>|WP_Error
     */
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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
     *
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function restoreTranslationLink(array $data): array|WP_Error
    {
        if (! function_exists('pll_save_post_translations')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $snapshot = TranslationLinkBeforeSnapshot::fromArray($data);

        $groups = [];
        foreach ($snapshot->before as $row) {
            if ($row->id && $row->lang !== '') {
                pll_set_post_language($row->id, $row->lang);
            }
            if (count($row->group) > 1) {
                $group = $row->group;
                ksort($group);
                $groups[implode(',', $group)] = $group;
            }
        }
        foreach ($groups as $group) {
            pll_save_post_translations($group);
        }

        return ['restored' => 'translation-link', 'count' => count($snapshot->before)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
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
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
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

        if ($restored === 0 && $errors) {
            return new WP_Error('restore_failed', 'Nothing was restored: '.implode(' ', $errors));
        }

        return ['restored' => 'bulk', 'count' => $restored, 'errors' => $errors];
    }
}
