<?php

namespace GeneroWP\MCP\Undo;

use GeneroWP\MCP\Abilities\NavMenuItemsAbility;
use GeneroWP\MCP\Abilities\ReadOnlyMode;
use GeneroWP\MCP\Integrations\GravityForms\GravityFormsAbility;
use GeneroWP\MCP\Integrations\Polylang\RegisteredStrings;
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
        // An undo writes too.
        if (ReadOnlyMode::enabled()) {
            return new WP_Error('gds_mcp_read_only', __('This site is in read-only mode.', 'gds-mcp'), ['status' => 403]);
        }

        $kind = (string) ($snapshot['kind'] ?? '');
        $data = (array) ($snapshot['data'] ?? []);

        // Nothing is undone over changes made since.
        if ($changed = UndoGuard::check($kind, $data)) {
            return $changed;
        }

        return match ($kind) {
            'restore-post' => self::restorePost($data),
            'restore-post-content' => self::restorePostContent($data),
            'restore-post-partial' => self::restorePostPartial($data),
            'restore-menu-order' => self::restoreMenuOrder($data),
            'untrash' => self::untrash($data),
            'trash' => self::trash($data),
            'trash-translation' => self::trashTranslation($data),
            'recreate-menu-item' => self::recreateMenuItem($data),
            'delete-menu-item' => self::deleteMenuItem($data),
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
        $current = get_post($snapshot->id);
        if (! $current) {
            return new WP_Error('restore_failed', "Post {$snapshot->id} no longer exists.");
        }
        // WordPress would quietly give the old slug a -2 suffix if another
        // post took it since; refuse instead of restoring a different URL.
        $slug = $snapshot->post['post_name'];
        if ($slug !== '' && $slug !== $current->post_name) {
            $unique = wp_unique_post_slug($slug, $snapshot->id, $snapshot->post['post_status'], $current->post_type, $snapshot->post['post_parent']);
            if ($unique !== $slug) {
                return new WP_Error('restore_failed', sprintf('Nothing was undone: the address "%s" this post had is now used by another post, so it would come back as "%s". Rename the other post first.', $slug, $unique));
            }
        }

        // wp_update_post()/add_post_meta() unslash their input; the snapshot
        // holds raw DB values, so slash them or backslashes are stripped.
        $result = wp_update_post(wp_slash(['ID' => $snapshot->id] + $snapshot->post), true);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($snapshot->sticky !== null && $snapshot->sticky !== is_sticky($snapshot->id)) {
            $snapshot->sticky ? stick_post($snapshot->id) : unstick_post($snapshot->id);
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
        $caveats = [];
        foreach ($terms as $taxonomy => $ids) {
            $set = wp_set_object_terms($snapshot->id, $ids, $taxonomy, false);
            if (is_wp_error($set)) {
                $caveats[] = sprintf('The %s terms were not restored: %s', $taxonomy, $set->get_error_message());
            }
        }

        // Saving filters content for users without unfiltered_html, so markup
        // someone else wrote may not come back as it was.
        if (get_post_field('post_content', $snapshot->id, 'raw') !== $snapshot->post['post_content']) {
            $caveats[] = 'The content was changed on save, e.g. by HTML filtering, so it differs from the snapshot. Check the post.';
        }

        $summary = ['restored' => 'post', 'id' => $snapshot->id];
        if ($caveats) {
            $summary['caveats'] = $caveats;
        }

        return $summary;
    }

    /**
     * Put back only a post's content, for writes that change nothing else
     * (blocks-patch, content-replace): a title or status changed since stays.
     *
     * data: { id, content }
     *
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function restorePostContent(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! get_post($id) || ! array_key_exists('content', $data)) {
            return new WP_Error('restore_failed', "Post {$id} no longer exists.");
        }
        $content = (string) $data['content'];

        $result = wp_update_post(wp_slash(['ID' => $id, 'post_content' => $content]), true);
        if (is_wp_error($result)) {
            return $result;
        }

        $summary = ['restored' => 'post', 'id' => $id];
        if (get_post_field('post_content', $id, 'raw') !== $content) {
            $summary['caveats'] = ['The content was changed on save, e.g. by HTML filtering, so it differs from the snapshot. Check the post.'];
        }

        return $summary;
    }

    /**
     * Put back a menu item's order, all a menu move or reorder changed on the
     * items around the one it moved.
     *
     * data: { id, menu_order }
     *
     * @param  array<string, mixed>  $data
     * @return RestoreSummary|WP_Error
     */
    private static function restoreMenuOrder(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if (get_post_type($id) !== 'nav_menu_item') {
            return new WP_Error('restore_failed', "Menu item {$id} no longer exists.");
        }
        $result = wp_update_post(['ID' => $id, 'menu_order' => (int) ($data['menu_order'] ?? 0)], true);

        return is_wp_error($result) ? $result : ['restored' => 'menu-order', 'id' => $id];
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

        // The meta goes back first, so the status update's save hooks see
        // it, and even when the status cannot; the error says so.
        foreach ($snapshot->metaPrev as $key => $values) {
            delete_post_meta($snapshot->id, $key);
            if ($values === null) {
                continue;
            }
            // Values are captured serialized, as stored (older rows hold
            // plain strings, written back as they are); add_post_meta
            // re-serializes as needed but also unslashes, hence wp_slash().
            foreach ($values as $value) {
                add_post_meta($snapshot->id, $key, wp_slash($snapshot->serialized ? maybe_unserialize($value) : $value));
            }
        }

        // With the status go the slug and dates publishing gave a draft.
        $postarr = ['ID' => $snapshot->id, 'post_status' => $snapshot->status];
        if (isset($snapshot->fields['post_name'])) {
            $postarr['post_name'] = $snapshot->fields['post_name'];
        }
        if (isset($snapshot->fields['post_date'])) {
            $postarr['post_date'] = $snapshot->fields['post_date'];
        }
        if (isset($snapshot->fields['post_date_gmt'])) {
            $postarr['post_date_gmt'] = $snapshot->fields['post_date_gmt'];
        }
        $updated = $snapshot->status !== '' ? wp_update_post(wp_slash($postarr), true) : null;
        if (is_wp_error($updated)) {
            return new WP_Error('restore_failed', "The meta of post {$snapshot->id} was restored, but not its status: ".$updated->get_error_message());
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

        // Trashing pointed the homepage settings away from the page; put
        // them back only if nobody chose other ones since.
        $options = array_map('strval', (array) ($data['options'] ?? []));
        $reset = ['show_on_front' => 'posts', 'page_on_front' => '0', 'page_for_posts' => '0'];
        foreach (array_intersect_key($options, $reset) as $option => $value) {
            if ((string) get_option($option) !== $reset[$option]) {
                return new WP_Error('changed_since', sprintf('Nothing was undone: this page was the site\'s %s, and the homepage settings were changed after it was trashed. Restore it from the trash in wp-admin and set it in Settings → Reading if it should be again.', $option === 'page_for_posts' ? 'posts page' : 'homepage'));
            }
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

        foreach (array_intersect_key($options, $reset) as $option => $value) {
            update_option($option, $value);
        }
        if (isset($data['sticky_posts']) && ! is_sticky($id)) {
            // The whole list when only this post left it, so its place in
            // the order comes back too.
            $before = array_values(array_map('intval', (array) $data['sticky_posts']));
            $now = array_values(array_map('intval', (array) get_option('sticky_posts', [])));
            $now === array_values(array_diff($before, [$id])) ? update_option('sticky_posts', $before) : stick_post($id);
        }

        return ['restored' => 'untrash', 'id' => $id];
    }

    /**
     * Undo a created translation: take it out of its translation group, so
     * the language can be translated again, and move it to the trash.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private static function trashTranslation(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id && get_post($id) && function_exists('PLL') && isset(PLL()->model)) {
            $group = pll_get_post_translations($id);
            PLL()->model->post->delete_translation($id);
            // A source left with no other translation keeps no group of
            // one, as before the create.
            $rest = array_values(array_diff(array_map('intval', $group), [$id]));
            if (count($rest) === 1 && count(pll_get_post_translations($rest[0])) <= 1) {
                PLL()->model->post->delete_translation($rest[0]);
            }
        }

        return self::trash($data);
    }

    /**
     * Undo a created menu item: delete it (menu items have no trash) and
     * close the gap its insert opened, against the menu as it is now.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private static function deleteMenuItem(array $data): array|WP_Error
    {
        $id = (int) ($data['id'] ?? 0);
        $item = get_post($id);
        if (! $item || $item->post_type !== 'nav_menu_item') {
            return ['restored' => 'delete-menu-item', 'id' => $id, 'note' => 'Already removed.'];
        }
        $children = get_posts(['post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_menu_item_menu_item_parent', 'meta_value' => (string) $id]);
        if ($children) {
            return new WP_Error('changed_since', 'Nothing was undone: menu items were added under this one since, and removing it would leave them without a parent. Move or remove them first.');
        }
        $menuIds = wp_get_object_terms($id, 'nav_menu', ['fields' => 'ids']);
        $order = (int) $item->menu_order;
        if (! wp_delete_post($id, true)) {
            return new WP_Error('restore_failed', "Failed to remove menu item {$id}.");
        }
        if (! is_wp_error($menuIds) && $menuIds) {
            NavMenuItemsAbility::shiftOrder((int) $menuIds[0], $order + 1, -1);
        }

        return ['restored' => 'delete-menu-item', 'id' => $id];
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
        // With EMPTY_TRASH_DAYS at 0, wp_trash_post() deletes for good.
        if (! EMPTY_TRASH_DAYS) {
            return new WP_Error('trash_not_supported', "The trash is disabled on this site (EMPTY_TRASH_DAYS is 0), so undoing would delete post {$id} permanently. Delete it in wp-admin if it should be removed for good.");
        }
        // Made the homepage or posts page since it was created: trashing it
        // would reset those settings too, which this undo could not give back.
        if (! empty(Snapshot::trashSideEffects($id)['options'])) {
            return new WP_Error('changed_since', 'Nothing was undone: this page has been made the site\'s homepage or posts page since it was created. Choose another one in Settings → Reading first.');
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
        if ($snapshot->menu !== null) {
            self::restoreMenuSettings($snapshot->termId, $snapshot->menu);
        }

        return ['restored' => 'term', 'term_id' => $snapshot->termId];
    }

    /**
     * Put back the theme locations a menu was in or took from another menu,
     * and its auto-add setting, which the menus endpoint writes with it.
     *
     * @param  array{locations: array<string, int>, auto_add: bool}  $menu
     */
    private static function restoreMenuSettings(int $menuId, array $menu): void
    {
        $locations = (array) get_theme_mod('nav_menu_locations', []);
        foreach (array_unique(array_merge(array_keys($locations), array_keys($menu['locations']))) as $location) {
            if ((int) ($locations[$location] ?? 0) !== $menuId && (int) ($menu['locations'][$location] ?? 0) !== $menuId) {
                continue;
            }
            if (array_key_exists($location, $menu['locations'])) {
                $locations[$location] = $menu['locations'][$location];
            } else {
                unset($locations[$location]);
            }
        }
        if ($locations !== (array) get_theme_mod('nav_menu_locations', [])) {
            set_theme_mod('nav_menu_locations', $locations);
        }

        $options = (array) get_option('nav_menu_options', []);
        $autoAdd = array_map('intval', (array) ($options['auto_add'] ?? []));
        if ($menu['auto_add'] !== in_array($menuId, $autoAdd, true)) {
            $options['auto_add'] = $menu['auto_add'] ? [...$autoAdd, $menuId] : array_values(array_diff($autoAdd, [$menuId]));
            // Nothing left in it: as before any menu used it.
            $options === ['auto_add' => []] ? delete_option('nav_menu_options') : update_option('nav_menu_options', $options);
        }
    }

    /**
     * Undo a created term by deleting it, unless something uses it now:
     * deleting would also take it off those posts, re-parent its children
     * and drop the menu items linking to it, none of which this undo
     * could give back.
     *
     * data: { term_id, taxonomy, locations?: theme location → menu id before a menu was created }
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private static function deleteTerm(array $data): array|WP_Error
    {
        global $wpdb;
        $termId = (int) ($data['term_id'] ?? 0);
        $taxonomy = (string) ($data['taxonomy'] ?? '');
        if (! $termId || ! term_exists($termId, $taxonomy)) {
            return ['restored' => 'delete-term', 'term_id' => $termId, 'note' => 'Already removed.'];
        }

        $used = [];
        $objects = get_objects_in_term($termId, $taxonomy);
        if (! is_wp_error($objects) && $objects) {
            $used[] = $taxonomy === 'nav_menu' ? sprintf('%d menu item(s)', count($objects)) : sprintf('%d post(s)', count($objects));
        }
        $children = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE parent = %d AND taxonomy = %s", $termId, $taxonomy));
        if ($children) {
            $used[] = sprintf('%d sub-term(s)', $children);
        }
        if ($taxonomy !== 'nav_menu' && wp_get_associated_nav_menu_items($termId, 'taxonomy', $taxonomy)) {
            $used[] = 'a menu item';
        }
        if ($used) {
            return new WP_Error('changed_since', sprintf('Nothing was undone: the term is now used by %s, and deleting it would take it off them too. Delete it in wp-admin if it should go.', implode(', ', $used)));
        }
        // wp_delete_term() refuses the default term (and returns 0).
        if ($termId === (int) get_option($taxonomy === 'category' ? 'default_category' : 'default_term_'.$taxonomy)) {
            return new WP_Error('restore_failed', 'Nothing was undone: the term has been made the default one since, which WordPress does not delete. Choose another default first.');
        }

        // The theme locations the new menu was put in.
        $locationsBefore = isset($data['locations']) && is_array($data['locations']) ? $data['locations'] : null;
        $taken = array_keys(array_filter((array) get_nav_menu_locations(), fn ($menuId) => (int) $menuId === $termId));

        // What Polylang does on pre_delete_term, which it only hooks when it
        // had languages at load: take the term out of its translation group
        // and drop its language, so the language can be translated again.
        // Inside that action, as Polylang then keeps no group for it.
        $unlink = static function ($deleting) use ($termId): void {
            if ((int) $deleting === $termId) {
                PLL()->model->term->delete_translation($termId);
                PLL()->model->term->delete_language($termId);
            }
        };
        $translated = function_exists('pll_get_term_language') && pll_get_term_language($termId);
        if ($translated) {
            add_action('pre_delete_term', $unlink);
        }
        $deleted = $taxonomy === 'nav_menu' ? wp_delete_nav_menu($termId) : wp_delete_term($termId, $taxonomy);
        if ($translated) {
            remove_action('pre_delete_term', $unlink);
        }
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        if (! $deleted) {
            // 0 for the default category, false when it was not found.
            return new WP_Error('restore_failed', "Term {$termId} could not be deleted (it may be the default term).");
        }

        // Deleting a menu empties its locations; give them back the menus
        // they had before it was created.
        if ($taken && $locationsBefore !== null) {
            $locations = (array) get_theme_mod('nav_menu_locations', []);
            foreach ($taken as $location) {
                if (array_key_exists($location, $locationsBefore)) {
                    $locations[$location] = (int) $locationsBefore[$location];
                } else {
                    unset($locations[$location]);
                }
            }
            set_theme_mod('nav_menu_locations', $locations);
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

        // The id alone, first, last or inside a comma-separated list, or as a
        // serialized string. LIKE, not REGEXP: MySQL's regex engine can fail
        // on a large value, which would refuse the undo.
        $metaMatch = '(meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)';
        $metaArgs = [(string) $id, $id.',%', '%,'.$id, '%,'.$id.',%', '%'.$wpdb->esc_like('"'.$id.'";').'%'];

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

        // The menu items the delete removed come back as posts of their own,
        // so they are not re-attached as objects.
        $itemIds = array_map(fn (array $item) => (int) ($item['id'] ?? 0), $snapshot->menuItems);
        $objectIds = array_values(array_filter($snapshot->objectIds, fn (int $id) => ! in_array($id, $itemIds, true) && get_post($id)));

        // Preferred path: re-insert with the original ids.
        if ($oldId && $ttId && ! $idReused && ! $ttReused) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery
            $inserted = $wpdb->insert($wpdb->terms, [
                'term_id' => $oldId,
                'name' => $snapshot->fields['name'],
                'slug' => $snapshot->fields['slug'] !== '' ? $snapshot->fields['slug'] : sanitize_title($snapshot->fields['name']),
                'term_group' => $snapshot->termGroup,
            ]);
            $inserted = $inserted && $wpdb->insert($wpdb->term_taxonomy, [
                'term_taxonomy_id' => $ttId,
                'term_id' => $oldId,
                'taxonomy' => $snapshot->taxonomy,
                'description' => $snapshot->fields['description'],
                'parent' => $snapshot->fields['parent'],
                'count' => 0,
            ]);
            // phpcs:enable
            clean_term_cache([$oldId], $snapshot->taxonomy);
            if (! $inserted) {
                $wpdb->delete($wpdb->terms, ['term_id' => $oldId]);
                clean_term_cache([$oldId], $snapshot->taxonomy);

                return new WP_Error('restore_failed', 'Nothing was undone: the term could not be put back. '.$wpdb->last_error);
            }

            self::restoreTermMeta($oldId, $snapshot->meta);
            self::reattachObjects($ttId, $objectIds);
            $caveats = self::restoreTermSideEffects($oldId, $oldId, $snapshot);
            wp_update_term_count_now([$ttId], $snapshot->taxonomy);
            self::restoreTermTranslation($oldId, $snapshot);

            $summary = [
                'restored' => 'recreate-term',
                'term_id' => $oldId,
                'note' => 'Term restored under its original id; existing references remain valid.',
            ];
            if ($caveats) {
                $summary['caveats'] = $caveats;
            }

            return $summary;
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
        foreach ($objectIds as $objectId) {
            wp_set_object_terms($objectId, [$newId], $snapshot->taxonomy, true);
        }
        $caveats = self::restoreTermSideEffects($oldId, $newId, $snapshot);
        self::restoreTermTranslation($newId, $snapshot);

        // Yoast keeps a deleted term's SEO entry under its id; move it to the
        // new one, as Yoast does when core splits a shared term.
        if (function_exists('wpseo_split_shared_term')) {
            wpseo_split_shared_term($oldId, $newId, 0, $snapshot->taxonomy);
            // Its indexable was built when the term was inserted, before the
            // entry moved; rebuild it.
            $term = get_term($newId, $snapshot->taxonomy);
            if ($term instanceof \WP_Term) {
                SeoFields::touchTerm($term);
            }
        }

        return [
            'restored' => 'recreate-term',
            'term_id' => $newId,
            'old_term_id' => $oldId,
            'caveats' => [...self::staleTermReferences($oldId, $newId), ...$caveats],
        ];
    }

    /**
     * Reverse what deleting the term did to others: point its former
     * children back at it, take the default term back off the posts that
     * got it, recreate the menu items the delete removed, and for a menu put
     * it back in its theme locations.
     *
     * @return list<string> What could not be put back as it was.
     */
    private static function restoreTermSideEffects(int $oldId, int $termId, TermForRecreateSnapshot $snapshot): array
    {
        global $wpdb;
        $caveats = [];

        // Core moved them up to the term's own parent; only those still there.
        $children = array_filter($snapshot->children, fn (int $child) => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s AND parent = %d",
            $child,
            $snapshot->taxonomy,
            $snapshot->fields['parent'],
        )) > 0);
        foreach ($children as $child) {
            $wpdb->update($wpdb->term_taxonomy, ['parent' => $termId], ['term_id' => $child, 'taxonomy' => $snapshot->taxonomy]);
        }
        if ($children) {
            clean_term_cache(array_values($children), $snapshot->taxonomy);
        }

        if ($snapshot->defaultTerm) {
            foreach ($snapshot->gainedDefault as $objectId) {
                if (get_post($objectId) && has_term($snapshot->defaultTerm, $snapshot->taxonomy, $objectId)) {
                    wp_remove_object_terms($objectId, $snapshot->defaultTerm, $snapshot->taxonomy);
                }
            }
        }

        $isMenu = $snapshot->taxonomy === 'nav_menu';
        $menuTtId = $isMenu ? (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu'", $termId)) : 0;
        $remap = [];
        foreach ($snapshot->menuItems as $payload) {
            $item = PostFieldsSnapshot::fromArray($payload);
            if (! $item) {
                continue;
            }
            $terms = $isMenu ? ['nav_menu' => [$termId]] + $item->terms : $item->terms;
            $meta = $item->meta;
            if (! $isMenu && $termId !== $oldId && ($meta['_menu_item_object_id'] ?? null) === [(string) $oldId]) {
                $meta['_menu_item_object_id'] = [(string) $termId];
            }
            $newId = self::recreatePost(new PostFieldsSnapshot($item->id, $item->post, $meta, $terms, true), 'nav_menu_item');
            if (is_wp_error($newId)) {
                $caveats[] = sprintf('Menu item "%s" (%d) could not be put back: %s', $item->post['post_title'], $item->id, $newId->get_error_message());

                continue;
            }
            if ($isMenu) {
                // wp_set_object_terms() may not resolve a term inserted raw.
                self::reattachObjects($menuTtId, [$newId]);
            }
            if ($newId !== $item->id) {
                $remap[$item->id] = $newId;
            }
        }
        // Sub-items point at their parent by id.
        foreach ($remap as $old => $new) {
            foreach (get_posts(['post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_menu_item_menu_item_parent', 'meta_value' => (string) $old]) as $child) {
                if (in_array((int) $child, $remap, true)) {
                    update_post_meta((int) $child, '_menu_item_menu_item_parent', (string) $new);
                }
            }
            $caveats[] = "Menu item {$old} came back under a new id ({$new}) because the old id had been reused.";
        }

        if ($isMenu && $snapshot->menu !== null) {
            $menu = $snapshot->menu;
            $menu['locations'] = array_map(fn (int $id) => $id === $oldId ? $termId : $id, $menu['locations']);
            self::restoreMenuSettings($termId, $menu);
        }

        return $caveats;
    }

    /**
     * Insert a deleted post again from its snapshot, under its original id
     * when that is free.
     */
    private static function recreatePost(PostFieldsSnapshot $snapshot, string $postType): int|WP_Error
    {
        // wp_insert_post() keeps import_id only when no post has that id.
        $newId = wp_insert_post(wp_slash(['import_id' => $snapshot->id, 'post_type' => $postType] + $snapshot->post), true);
        if (is_wp_error($newId)) {
            return $newId;
        }
        $restored = self::restorePost((new PostFieldsSnapshot($newId, $snapshot->post, $snapshot->meta, $snapshot->terms, true))->toArray());
        if (is_wp_error($restored)) {
            wp_delete_post($newId, true);

            return $restored;
        }

        return $newId;
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
     * Polylang removes a term's language and translation link when it is
     * deleted; put both back, linking only the translations still there.
     */
    private static function restoreTermTranslation(int $termId, TermForRecreateSnapshot $snapshot): void
    {
        if ($snapshot->language === '' || ! function_exists('pll_set_term_language')) {
            return;
        }
        pll_set_term_language($termId, $snapshot->language);

        $group = array_filter($snapshot->translations, fn ($id) => $id !== $snapshot->termId && get_term($id) instanceof \WP_Term);
        if ($group) {
            pll_save_term_translations([$snapshot->language => $termId] + $group);
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
        $current = \GFAPI::get_form($id);
        if (! $current) {
            return new WP_Error('restore_failed', "Form {$id} no longer exists.");
        }

        // Fields the restore removes or retypes lose the values entries
        // submitted since hold for them; GF has no form revisions to undo that.
        $lost = isset($form['fields']) ? self::fieldsWithEntryValues($id, GravityFormsAbility::destructiveFieldChanges(json_decode((string) wp_json_encode($current['fields'] ?? []), true) ?: [], (array) $form['fields'])) : [];
        if ($lost) {
            return new WP_Error('changed_since', sprintf('Nothing was undone: entries submitted since hold values for %s, which undoing this change would remove or change the type of, so those values would be lost. Edit the form by hand if it should change back.', implode(', ', $lost)));
        }

        $stored = is_array($data['stored'] ?? null) ? $data['stored'] : null;
        if ($stored) {
            return self::restoreStoredForm($id, $stored);
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
     * Write back a form's rows as {@see Snapshot::gravityForm()} read them,
     * the way GFFormsModel::update_form_meta() stores them.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>|WP_Error
     */
    private static function restoreStoredForm(int $id, array $stored): array|WP_Error
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $meta = array_intersect_key($stored, array_flip(['display_meta', 'confirmations', 'notifications']));
        $ok = $wpdb->update(\GFFormsModel::get_meta_table_name(), $meta, ['form_id' => $id]) !== false;
        $ok = $ok && $wpdb->update(\GFFormsModel::get_form_table_name(), ['title' => (string) ($stored['title'] ?? ''), 'is_active' => (string) ($stored['is_active'] ?? '1')], ['id' => $id]) !== false;
        // phpcs:enable
        \GFFormsModel::flush_current_forms();
        if (! $ok) {
            return new WP_Error('restore_failed', "Failed to restore form {$id}: ".$wpdb->last_error);
        }
        // As GF fires them after a save.
        foreach ($meta as $name => $value) {
            do_action('gform_post_update_form_meta', $value, $id, $name);
            do_action("gform_post_update_form_meta_{$id}", $value, $id, $name);
        }

        return ['restored' => 'form', 'id' => $id];
    }

    /**
     * The labels of the given fields that entries hold a value for.
     *
     * @param  array<int, array{id: int, label: string, reason: string}>  $fields
     * @return list<string>
     */
    private static function fieldsWithEntryValues(int $formId, array $fields): array
    {
        global $wpdb;
        $lost = [];
        foreach ($fields as $field) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.\GFFormsModel::get_entry_meta_table_name()." WHERE form_id = %d AND (meta_key = %s OR meta_key LIKE %s) AND meta_value != ''", $formId, (string) $field['id'], $wpdb->esc_like($field['id'].'.').'%'));
            if ($count) {
                $lost[] = sprintf('"%s" (%d entries)', $field['label'], $count);
            }
        }

        return $lost;
    }

    /**
     * Undo a created form by deleting it, unless it has entries now:
     * deleting the form would delete them with it, for good.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private static function deleteForm(array $data): array|WP_Error
    {
        global $wpdb;
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }
        $id = (int) ($data['id'] ?? 0);
        if (! $id || ! \GFAPI::get_form($id)) {
            return ['restored' => 'delete-form', 'id' => $id, 'note' => 'Already removed.'];
        }
        // Every status, spam and trash included.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $entries = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.\GFFormsModel::get_entry_table_name().' WHERE form_id = %d', $id));
        if ($entries) {
            return new WP_Error('changed_since', sprintf('Nothing was undone: the form has %d entries now, and deleting it would delete them for good. Export them first, then delete the form in wp-admin, or move it to the trash there.', $entries));
        }
        $deleted = \GFAPI::delete_form($id);
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        if (\GFAPI::get_form($id)) {
            return new WP_Error('restore_failed', "Failed to delete form {$id}.");
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
        $feed = \GFAPI::get_feed($feedId);
        if (is_wp_error($feed) || ! $feed) {
            return new WP_Error('restore_failed', "Feed {$feedId} no longer exists.");
        }
        // update_feed() only finds active feeds, so an edit that turned the
        // feed off could never be undone; switch it on for the write, the
        // properties below put the snapshot's state back.
        if (! (int) $feed['is_active'] && is_wp_error(\GFAPI::update_feed_property($feedId, 'is_active', 1))) {
            return new WP_Error('restore_failed', "Failed to restore feed {$feedId}.");
        }
        $result = \GFAPI::update_feed($feedId, $meta);
        if (is_wp_error($result) || $result === false) {
            if (! (int) $feed['is_active']) {
                \GFAPI::update_feed_property($feedId, 'is_active', 0);
            }

            return new WP_Error('restore_failed', "Failed to restore feed {$feedId}: ".(is_wp_error($result) ? $result->get_error_message() : 'GFAPI::update_feed returned false.'));
        }

        // update_feed only restores meta; the form binding / active state /
        // order are separate properties that the edit could also have changed.
        if ($failed = self::restoreFeedProperties($feedId, $data)) {
            return $failed;
        }

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

        global $wpdb;
        $newId = \GFAPI::add_feed($formId, $meta, $addon);
        if (is_wp_error($newId)) {
            return $newId;
        }
        $newId = (int) $newId;

        // Back under its old id when that is free, so entries that record
        // which feeds processed them still match. GF encrypted the meta on
        // the insert; only the id changes.
        $oldId = (int) ($data['feed_id'] ?? 0);
        $table = $wpdb->prefix.'gf_addon_feed';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($oldId && ! $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $oldId))
            && $wpdb->update($table, ['id' => $oldId], ['id' => $newId])) {
            $newId = $oldId;
        }
        // phpcs:enable

        if ($failed = self::restoreFeedProperties($newId, $data)) {
            \GFAPI::delete_feed($newId);

            return $failed;
        }

        $summary = ['restored' => 'recreate-feed', 'feed_id' => $newId];
        if ($newId !== $oldId) {
            $summary['caveats'] = ["The feed came back under a new id ({$newId}), so entries already processed by the old one may be sent again if they are resent."];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function restoreFeedProperties(int $feedId, array $data): ?WP_Error
    {
        foreach (['form_id', 'is_active', 'feed_order'] as $property) {
            if (! array_key_exists($property, $data)) {
                continue;
            }
            $result = \GFAPI::update_feed_property($feedId, $property, (int) $data[$property]);
            if (is_wp_error($result)) {
                return new WP_Error('restore_failed', sprintf('The %s of feed %d could not be restored: %s', $property, $feedId, $result->get_error_message()));
            }
        }
        // False from update_feed_property() also means "unchanged" since
        // GF 3.0, so check what was stored.
        $feed = \GFAPI::get_feed($feedId);
        foreach (['form_id', 'is_active', 'feed_order'] as $property) {
            if (array_key_exists($property, $data) && (is_wp_error($feed) || ! $feed || (int) $feed[$property] !== (int) $data[$property])) {
                return new WP_Error('restore_failed', sprintf('The %s of feed %d could not be restored.', $property, $feedId));
            }
        }

        return null;
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
        if (! $item) {
            return ['restored' => 'delete-redirect', 'item_id' => $itemId, 'note' => 'Already removed.'];
        }
        $item->delete();
        if (\Red_Item::get_by_id($itemId)) {
            return new WP_Error('restore_failed', "Failed to delete redirect {$itemId}.");
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
            // update_post_meta() unslashes.
            update_post_meta($postId, '_yoast_wpseo_redirect', wp_slash($prev));
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
        $feed = $feedId ? \GFAPI::get_feed($feedId) : null;
        if (! $feed || is_wp_error($feed)) {
            return ['restored' => 'delete-feed', 'feed_id' => $feedId, 'note' => 'Already removed.'];
        }
        $deleted = \GFAPI::delete_feed($feedId);
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        $left = \GFAPI::get_feed($feedId);
        if ($left && ! is_wp_error($left)) {
            return new WP_Error('restore_failed', "Failed to delete feed {$feedId}.");
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
            } elseif ($row->id) {
                // It stood alone before: take it out of the group it joined.
                PLL()->model->post->delete_translation($row->id);
            }
        }
        foreach ($groups as $group) {
            pll_save_post_translations($group);
        }

        // Polylang's setters return nothing useful; check what it stored.
        $wrong = [];
        foreach ($snapshot->before as $row) {
            if (! $row->id) {
                continue;
            }
            $group = count($row->group) > 1 ? $row->group : [];
            $now = pll_get_post_translations($row->id);
            $now = count($now) > 1 ? $now : [];
            ksort($group);
            ksort($now);
            if (($row->lang !== '' && pll_get_post_language($row->id) !== $row->lang) || array_map('intval', $now) !== $group) {
                $wrong[] = $row->id;
            }
        }
        if ($wrong) {
            return new WP_Error('restore_failed', 'The translation links were only partly put back; check posts '.implode(', ', $wrong).'.');
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
        if ($translation === '') {
            // It had no translation: remove the entry rather than store an
            // empty one.
            unset($mo->entries[$mo->make_entry($string, '')->key()]);
        } else {
            $translation = RegisteredStrings::sanitizeTranslation($string, $translation, $mo->translate_if_any($string));
            $mo->add_entry($mo->make_entry($string, $translation));
        }
        $mo->export_to_db($langObject);
        if (! $mo->entries) {
            // Nothing left: as before any string was translated.
            delete_term_meta($langObject->term_id, '_pll_strings_translations');
        }

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
        $items = array_values(array_filter((array) ($data['items'] ?? []), fn ($item) => is_array($item) && ! empty($item['kind'])));

        // All or nothing for later changes: if any item was changed since,
        // undo none of them rather than leave half the batch reverted.
        $changed = [];
        foreach ($items as $item) {
            $error = UndoGuard::check((string) $item['kind'], (array) ($item['data'] ?? []));
            if ($error) {
                $changed[] = $error->get_error_message();
            }
        }
        if ($changed) {
            return new WP_Error('changed_since', 'Nothing was undone: '.implode(' ', array_unique($changed)));
        }

        // Newest first, the reverse of the order they were written in, so
        // each item sees the state it left behind.
        $restored = 0;
        $errors = [];
        $caveats = [];
        foreach (array_reverse($items) as $item) {
            $result = self::handle(null, $item);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $restored++;
                array_push($caveats, ...(array) ($result['caveats'] ?? []));
            }
        }

        if ($restored === 0 && $errors) {
            return new WP_Error('restore_failed', 'Nothing was restored: '.implode(' ', $errors));
        }

        $summary = ['restored' => 'bulk', 'count' => $restored, 'errors' => $errors];
        if ($errors) {
            // gds-assistant shows caveats with the undo; the first says
            // plainly that this was not a full undo.
            $summary['partial'] = true;
            $caveats = [sprintf('Only partly undone: %d of %d changes were reverted. Not reverted: %s', $restored, count($items), implode(' ', $errors)), ...$caveats];
        }
        if ($caveats) {
            $summary['caveats'] = $caveats;
        }

        return $summary;
    }
}
