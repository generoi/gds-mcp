<?php

namespace GeneroWP\MCP\Undo;

use GeneroWP\MCP\Undo\Snapshots\PartialPostSnapshot;
use GeneroWP\MCP\Undo\Snapshots\PostFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermForRecreateSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TranslationLinkBeforeSnapshot;

/**
 * Captures the "before" state of an object so a write can be undone.
 *
 * Mutating abilities call a capture helper before they change anything and
 * attach the result to their successful response under the `_undo` key (via
 * {@see Reversible::reversible()}). gds-assistant peels `_undo` off the result —
 * it never reaches the LLM — and stores it on the audit-log row. To undo, it
 * passes the snapshot back through the `gds-mcp/restore_snapshot` filter,
 * which {@see RestoreSnapshot} handles.
 *
 * Helpers here are thin factories over the concrete snapshot value classes in
 * `Snapshots\` — abilities mostly want a one-liner and don't care about the
 * class layout. Calls that need to inspect or massage the snapshot reach for
 * the class directly.
 *
 * Each helper returns the typed snapshot object (or `null` when the target
 * has already gone away — the ability uses that to skip attaching `_undo`).
 */
final class Snapshot
{
    public static function postFields(int $id): ?PostFieldsSnapshot
    {
        return PostFieldsSnapshot::capture($id);
    }

    /**
     * Just a post's content, for writes that change nothing else, so their
     * undo leaves the title, status and meta as they are by then.
     *
     * @return array{id: int, content: string}|null
     */
    public static function postContent(int $id): ?array
    {
        $post = get_post($id);

        return $post ? ['id' => $id, 'content' => (string) $post->post_content] : null;
    }

    /**
     * Minimal capture for a partial (merge) restore: the current status plus
     * the prior values of ONLY the given meta keys. Used by bulk-update so a
     * large batch never snapshots full content/meta and the restore won't
     * clobber meta it didn't change.
     *
     * @param  array<int, string>  $metaKeys
     */
    public static function partialPost(int $id, array $metaKeys): ?PartialPostSnapshot
    {
        return PartialPostSnapshot::capture($id, $metaKeys);
    }

    /**
     * Capture prior languages + full translation groups for every post a
     * relink touches.
     *
     * @param  list<int>  $postIds
     */
    public static function translationLinkBefore(array $postIds): TranslationLinkBeforeSnapshot
    {
        return TranslationLinkBeforeSnapshot::capture($postIds);
    }

    /**
     * What trashing a post resets besides its status: core's
     * _reset_front_page_settings_for_post() points the homepage settings
     * away from a trashed page and unsticks the post. Holds the values to
     * put back: `options` (name → prior value) and `sticky_posts` (the
     * whole prior list, so its order comes back too).
     *
     * @return array{options?: array<string, string>, sticky_posts?: list<int>}
     */
    public static function trashSideEffects(int $id): array
    {
        $effects = [];
        if (get_post_type($id) === 'page') {
            if ((int) get_option('page_on_front') === $id) {
                $effects['options']['show_on_front'] = (string) get_option('show_on_front');
                $effects['options']['page_on_front'] = (string) $id;
            }
            if ((int) get_option('page_for_posts') === $id) {
                $effects['options']['page_for_posts'] = (string) $id;
            }
        }
        if (is_sticky($id)) {
            $effects['sticky_posts'] = array_values(array_map('intval', (array) get_option('sticky_posts', [])));
        }

        return $effects;
    }

    /**
     * A Gravity Forms form as stored: its title and active flag, and its
     * meta rows as the JSON strings GF wrote. Restoring these puts the form
     * back byte for byte; saving the decoded form through GFAPI would add
     * the defaults GF fills in on every save.
     *
     * @return array{title: string, is_active: string, display_meta: ?string, confirmations: ?string, notifications: ?string}|null
     */
    public static function gravityForm(int $id): ?array
    {
        global $wpdb;
        if (! class_exists('GFFormsModel')) {
            return null;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $form = $wpdb->get_row($wpdb->prepare('SELECT title, is_active FROM '.\GFFormsModel::get_form_table_name().' WHERE id = %d', $id), ARRAY_A);
        $meta = $wpdb->get_row($wpdb->prepare('SELECT display_meta, confirmations, notifications FROM '.\GFFormsModel::get_meta_table_name().' WHERE form_id = %d', $id), ARRAY_A);
        // phpcs:enable
        if (! $form || ! $meta) {
            return null;
        }

        return [
            'title' => (string) $form['title'],
            'is_active' => (string) $form['is_active'],
            'display_meta' => $meta['display_meta'],
            'confirmations' => $meta['confirmations'],
            'notifications' => $meta['notifications'],
        ];
    }

    public static function termFields(int $termId, string $taxonomy): ?TermFieldsSnapshot
    {
        return TermFieldsSnapshot::capture($termId, $taxonomy);
    }

    public static function termForRecreate(int $termId, string $taxonomy): ?TermForRecreateSnapshot
    {
        return TermForRecreateSnapshot::capture($termId, $taxonomy);
    }
}
