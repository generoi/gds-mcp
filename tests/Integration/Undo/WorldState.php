<?php

namespace GeneroWP\MCP\Tests\Integration\Undo;

use GeneroWP\MCP\Undo\RestoreSnapshot;

/**
 * Everything a write, or WordPress's hooks reacting to it, can touch, read
 * straight from the database: an undo round trip captures it before the
 * write and after the undo, and the two must be the same.
 *
 * Left out: post_modified (every save bumps it), revisions (history, not
 * state), WordPress's own bookkeeping meta, and the posts named in
 * {@see self::without()} (what an undo of a create moves to the trash).
 */
final class WorldState
{
    /** Meta that records history or is consumed by cron, never restored. */
    private const IGNORED_META = ['_encloseme', '_pingme'];

    private const OPTIONS = ['show_on_front', 'page_on_front', 'page_for_posts', 'sticky_posts', 'default_category', 'wpseo_taxonomy_meta', 'nav_menu_options'];

    /**
     * @return array<string, mixed>
     */
    public static function capture(): array
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $state = ['posts' => [], 'postmeta' => [], 'terms' => [], 'termmeta' => [], 'relationships' => []];

        foreach ($wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type != 'revision' ORDER BY ID", ARRAY_A) as $row) {
            unset($row['post_modified'], $row['post_modified_gmt']);
            $state['posts'][(int) $row['ID']] = $row;
        }

        $ignored = array_merge(RestoreSnapshot::BOOKKEEPING_META, self::IGNORED_META);
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A) as $row) {
            $postId = (int) $row['post_id'];
            if (! isset($state['posts'][$postId]) || in_array($row['meta_key'], $ignored, true)) {
                continue;
            }
            $state['postmeta'][$postId][$row['meta_key']][] = $row['meta_value'];
        }
        foreach ($state['postmeta'] as &$meta) {
            ksort($meta);
        }
        unset($meta);

        foreach ($wpdb->get_results("SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id ORDER BY t.term_id", ARRAY_A) as $row) {
            $state['terms'][(int) $row['term_id']] = $row;
        }
        foreach ($wpdb->get_results("SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} ORDER BY meta_id", ARRAY_A) as $row) {
            $state['termmeta'][(int) $row['term_id']][$row['meta_key']][] = $row['meta_value'];
        }
        foreach ($state['termmeta'] as &$meta) {
            ksort($meta);
        }
        unset($meta);

        foreach ($wpdb->get_results("SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A) as $row) {
            if (isset($state['posts'][(int) $row['object_id']])) {
                $state['relationships'][(int) $row['object_id']][] = (int) $row['term_taxonomy_id'];
            }
        }

        foreach (self::OPTIONS as $option) {
            $state['options'][$option] = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
        }
        $state['nav_menu_locations'] = get_theme_mod('nav_menu_locations', []);

        if (class_exists('GFAPI')) {
            $state['gf_forms'] = $wpdb->get_results("SELECT f.*, m.display_meta, m.confirmations, m.notifications FROM {$wpdb->prefix}gf_form f LEFT JOIN {$wpdb->prefix}gf_form_meta m ON m.form_id = f.id ORDER BY f.id", ARRAY_A);
            $state['gf_entry_counts'] = $wpdb->get_results("SELECT form_id, status, COUNT(*) AS entries FROM {$wpdb->prefix}gf_entry GROUP BY form_id, status ORDER BY form_id, status", ARRAY_A);
            $state['gf_feeds'] = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gf_addon_feed ORDER BY id", ARRAY_A);
        }
        if (class_exists('Red_Item')) {
            $state['redirection_items'] = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items ORDER BY id", ARRAY_A);
        }
        // phpcs:enable

        return $state;
    }

    /**
     * The state with these posts left out, for comparing the world after
     * an undo that moved them to the trash with the world before they
     * were created.
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $postIds
     * @return array<string, mixed>
     */
    public static function without(array $state, array $postIds): array
    {
        foreach ($postIds as $id) {
            unset($state['posts'][$id], $state['postmeta'][$id], $state['relationships'][$id]);
        }

        return $state;
    }

    /**
     * Every path where the two states differ, as "path: before => after".
     *
     * @return list<string>
     */
    public static function diff(mixed $before, mixed $after, string $path = ''): array
    {
        if (! is_array($before) || ! is_array($after)) {
            return $before === $after ? [] : [sprintf('%s: %s => %s', $path, var_export($before, true), var_export($after, true))];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $sub = $path === '' ? (string) $key : "{$path}.{$key}";
            if (! array_key_exists($key, $before)) {
                $out[] = sprintf('%s: (missing) => %s', $sub, var_export($after[$key], true));
            } elseif (! array_key_exists($key, $after)) {
                $out[] = sprintf('%s: %s => (missing)', $sub, var_export($before[$key], true));
            } else {
                array_push($out, ...self::diff($before[$key], $after[$key], $sub));
            }
        }

        return $out;
    }
}
