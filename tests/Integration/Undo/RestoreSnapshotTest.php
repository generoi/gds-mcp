<?php

namespace GeneroWP\MCP\Tests\Integration\Undo;

use GeneroWP\MCP\Tests\TestCase;
use GeneroWP\MCP\Undo\RestoreSnapshot;
use GeneroWP\MCP\Undo\Snapshot;

/**
 * Round-trips each undo snapshot kind against real WordPress: capture a
 * "before", mutate, restore, assert the prior state is back.
 */
class RestoreSnapshotTest extends TestCase
{
    /**
     * @param  array<string, mixed>|object  $data  Snapshot value object (any class with toArray()) or a raw array.
     */
    private function restore(string $kind, array|object $data): mixed
    {
        if (is_object($data)) {
            $data = $data->toArray();
        }

        return RestoreSnapshot::handle(null, ['kind' => $kind, 'data' => $data]);
    }

    public function test_restore_post_reverts_fields_meta_and_terms(): void
    {
        $catKeep = self::factory()->category->create();
        $catNew = self::factory()->category->create();
        $id = self::factory()->post->create(['post_title' => 'Original', 'post_content' => 'Before']);
        update_post_meta($id, 'subtitle', 'old subtitle');
        wp_set_object_terms($id, [$catKeep], 'category');

        $before = Snapshot::postFields($id);

        // Mutate everything the snapshot covers.
        wp_update_post(['ID' => $id, 'post_title' => 'Changed', 'post_content' => 'After']);
        update_post_meta($id, 'subtitle', 'new subtitle');
        wp_set_object_terms($id, [$catNew], 'category');

        $this->assertNotWPError($this->restore('restore-post', $before));

        clean_post_cache($id);
        $this->assertSame('Original', get_post($id)->post_title);
        $this->assertSame('Before', get_post($id)->post_content);
        $this->assertSame('old subtitle', get_post_meta($id, 'subtitle', true));
        $terms = wp_get_object_terms($id, 'category', ['fields' => 'ids']);
        $this->assertContains($catKeep, $terms);
        $this->assertNotContains($catNew, $terms);
    }

    public function test_restore_post_only_rewrites_meta_that_changed(): void
    {
        global $wpdb;
        $id = self::factory()->post->create();
        update_post_meta($id, 'untouched', 'same');
        update_post_meta($id, 'edited', 'before');
        $metaId = (int) $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'untouched'", $id));

        $before = Snapshot::postFields($id);
        update_post_meta($id, 'edited', 'after');
        update_post_meta($id, 'added_later', 'new');

        $this->assertNotWPError($this->restore('restore-post', $before));

        $this->assertSame('before', get_post_meta($id, 'edited', true));
        $this->assertSame('', get_post_meta($id, 'added_later', true), 'The snapshot records every key, so a key added since is removed.');
        $this->assertSame($metaId, (int) $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'untouched'", $id)), 'Unchanged meta is not rewritten.');
    }

    public function test_restore_post_from_an_old_snapshot_keeps_unknown_meta_and_fields(): void
    {
        $author = self::factory()->user->create();
        $id = self::factory()->post->create(['post_author' => $author, 'post_title' => 'Old']);
        $before = Snapshot::postFields($id)->toArray();
        // Shape written before meta_complete and the extra fields existed.
        unset($before['meta_complete'], $before['post']['post_author'], $before['post']['post_date'], $before['post']['post_date_gmt'], $before['post']['post_password'], $before['post']['comment_status']);

        $other = self::factory()->user->create();
        wp_update_post(['ID' => $id, 'post_title' => 'New', 'post_author' => $other]);
        update_post_meta($id, 'added_later', 'kept');

        $this->assertNotWPError($this->restore('restore-post', $before));

        clean_post_cache($id);
        $this->assertSame('Old', get_post($id)->post_title);
        $this->assertSame($other, (int) get_post($id)->post_author, 'An old snapshot has no author to restore.');
        $this->assertSame('kept', get_post_meta($id, 'added_later', true));
    }

    public function test_restore_post_reverts_author_date_password_and_comments(): void
    {
        $author = self::factory()->user->create();
        $id = self::factory()->post->create([
            'post_author' => $author,
            'post_date' => '2020-01-02 03:04:05',
            'post_password' => '',
            'comment_status' => 'open',
        ]);
        $before = Snapshot::postFields($id);

        wp_update_post([
            'ID' => $id,
            'post_author' => self::factory()->user->create(),
            'post_date' => '2024-06-07 08:09:10',
            'post_date_gmt' => '2024-06-07 08:09:10',
            'post_password' => 'secret',
            'comment_status' => 'closed',
        ]);

        $this->assertNotWPError($this->restore('restore-post', $before));

        clean_post_cache($id);
        $post = get_post($id);
        $this->assertSame($author, (int) $post->post_author);
        $this->assertSame('2020-01-02 03:04:05', $post->post_date);
        $this->assertSame('', $post->post_password);
        $this->assertSame('open', $post->comment_status);
    }

    public function test_restore_post_preserves_backslashes(): void
    {
        $content = '<!-- wp:paragraph {"placeholder":"say \"hi\" \u003cb\u003e"} --><p>C:\temp</p><!-- /wp:paragraph -->';
        $json = '{"label":"a \"quoted\" \u003cword\u003e"}';
        $id = self::factory()->post->create(['post_content' => wp_slash($content)]);
        update_post_meta($id, 'json_field', wp_slash($json));

        $before = Snapshot::postFields($id);
        wp_update_post(['ID' => $id, 'post_content' => 'Changed']);
        update_post_meta($id, 'json_field', 'changed');

        $this->assertNotWPError($this->restore('restore-post', $before));

        clean_post_cache($id);
        // Without unfiltered_html, kses re-serializes block attributes (" may
        // come back as \u0022), so compare the parsed attributes.
        $this->assertSame(parse_blocks($content)[0]['attrs'], parse_blocks(get_post($id)->post_content)[0]['attrs']);
        $this->assertStringContainsString('C:\temp', get_post($id)->post_content);
        $this->assertSame($json, get_post_meta($id, 'json_field', true));
    }

    public function test_restore_post_partial_preserves_backslashes(): void
    {
        $json = '{"label":"a \"quoted\" \u003cword\u003e"}';
        $id = self::factory()->post->create();
        update_post_meta($id, 'json_field', wp_slash($json));

        $before = Snapshot::partialPost($id, ['json_field']);
        update_post_meta($id, 'json_field', 'changed');

        $this->assertNotWPError($this->restore('restore-post-partial', $before));
        $this->assertSame($json, get_post_meta($id, 'json_field', true));
    }

    public function test_untrash_restores_a_trashed_post(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);
        wp_trash_post($id);
        $this->assertSame('trash', get_post_status($id));

        $this->assertNotWPError($this->restore('untrash', ['id' => $id]));
        $this->assertNotSame('trash', get_post_status($id));
    }

    public function test_trash_undoes_a_create(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);

        $this->assertNotWPError($this->restore('trash', ['id' => $id]));
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_restore_term_reverts_name(): void
    {
        $term = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'Original name']);
        $before = Snapshot::termFields($term, 'category');

        wp_update_term($term, 'category', ['name' => 'Renamed']);
        $this->assertNotWPError($this->restore('restore-term', $before));

        $this->assertSame('Original name', get_term($term, 'category')->name);
    }

    public function test_recreate_term_preserves_original_id_and_assignments(): void
    {
        $term = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'Recoverable']);
        $post = self::factory()->post->create();
        wp_set_object_terms($post, [$term], 'category');

        $before = Snapshot::termForRecreate($term, 'category');
        $this->assertNotNull($before);
        $this->assertSame($term, $before->termId);
        $this->assertContains($post, $before->objectIds);

        wp_delete_term($term, 'category');
        $this->assertNull(term_exists($term, 'category'));

        $result = $this->restore('recreate-term', $before);
        $this->assertNotWPError($result);

        // The whole point: same id back, so references stay valid.
        $this->assertSame($term, $result['term_id'], 'Term must be restored under its ORIGINAL id.');
        $this->assertNotNull(term_exists($term, 'category'));
        clean_post_cache($post);
        $this->assertContains($term, wp_get_object_terms($post, 'category', ['fields' => 'ids']));
    }

    public function test_restore_post_partial_reverts_only_listed_meta(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);
        update_post_meta($id, 'touched', 'old');
        update_post_meta($id, 'untouched', 'keep me');

        $before = Snapshot::partialPost($id, ['touched']);

        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        update_post_meta($id, 'touched', 'new');
        update_post_meta($id, 'untouched', 'changed after snapshot');

        $this->assertNotWPError($this->restore('restore-post-partial', $before));

        clean_post_cache($id);
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('old', get_post_meta($id, 'touched', true), 'Listed meta is reverted.');
        $this->assertSame('changed after snapshot', get_post_meta($id, 'untouched', true), 'Unlisted meta is left alone (merge, not wipe).');
    }

    public function test_bulk_replays_sub_snapshots(): void
    {
        $a = self::factory()->post->create(['post_status' => 'publish']);
        $b = self::factory()->post->create(['post_status' => 'publish']);
        $items = [
            ['kind' => 'restore-post-partial', 'data' => Snapshot::partialPost($a, [])],
            ['kind' => 'restore-post-partial', 'data' => Snapshot::partialPost($b, [])],
        ];

        wp_update_post(['ID' => $a, 'post_status' => 'draft']);
        wp_update_post(['ID' => $b, 'post_status' => 'draft']);

        $result = $this->restore('bulk', ['items' => $items]);
        $this->assertNotWPError($result);
        $this->assertSame(2, $result['count']);
        $this->assertSame('publish', get_post_status($a));
        $this->assertSame('publish', get_post_status($b));
    }

    public function test_unknown_kind_errors(): void
    {
        $result = $this->restore('nonsense-kind', []);
        $this->assertWPError($result);
        $this->assertSame('unknown_undo_kind', $result->get_error_code());
    }
}
