<?php

namespace GeneroWP\MCP\Tests\Integration\Undo;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * End-to-end undo: drive the REAL abilities (through their REST-base inputs and
 * output validation), then replay the captured `_undo` through the
 * gds-mcp/restore_snapshot filter exactly as gds-assistant would.
 *
 * This is the layer that catches bugs the RestoreSnapshotTest can't: that one
 * feeds restore handlers the real taxonomy slug directly, whereas abilities
 * receive a REST base (e.g. "categories", not "category") — the difference
 * that broke term-delete undo in testing.
 */
class UndoRoundTripTest extends AbilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function restore(array $result): mixed
    {
        $undo = $result['_undo'] ?? null;
        $this->assertIsArray($undo, 'Ability result must carry an _undo envelope.');
        $this->assertNotEmpty($undo['kind'], 'The _undo envelope must have a kind.');

        return apply_filters('gds-mcp/restore_snapshot', null, ['kind' => $undo['kind'], 'data' => $undo['data']]);
    }

    public function test_terms_delete_undo_restores_term_under_same_id_with_assignments(): void
    {
        $termId = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'Round Trip']);
        $postId = self::factory()->post->create();
        wp_set_object_terms($postId, [$termId], 'category');

        // The ability takes the REST base "categories", not the slug "category".
        $result = $this->assertAbilitySuccess('gds/terms-delete', ['taxonomy' => 'categories', 'id' => $termId, 'force' => true]);
        $this->assertSame('recreate-term', $result['_undo']['kind'] ?? null);
        $this->assertSame('category', $result['_undo']['data']['taxonomy'] ?? null, 'Snapshot must store the real taxonomy slug.');
        $this->assertNull(term_exists($termId, 'category'), 'Term is gone after delete.');

        $this->assertNotWPError($this->restore($result));

        clean_term_cache([$termId], 'category');
        clean_object_term_cache($postId, 'post');
        $this->assertNotNull(term_exists($termId, 'category'), 'Term restored under its original id.');
        $this->assertContains($termId, wp_get_object_terms($postId, 'category', ['fields' => 'ids']), 'Post reattached to the restored term.');
    }

    public function test_terms_update_undo_reverts_name(): void
    {
        $termId = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'Before']);

        $result = $this->assertAbilitySuccess('gds/terms-update', ['taxonomy' => 'categories', 'id' => $termId, 'name' => 'After']);
        $this->assertSame('restore-term', $result['_undo']['kind'] ?? null);
        $this->assertSame('After', get_term($termId, 'category')->name);

        $this->assertNotWPError($this->restore($result));
        $this->assertSame('Before', get_term($termId, 'category')->name);
    }

    public function test_terms_create_undo_deletes_term(): void
    {
        $result = $this->assertAbilitySuccess('gds/terms-create', ['taxonomy' => 'categories', 'name' => 'Created '.uniqid()]);
        $termId = (int) $result['id'];
        $this->assertSame('delete-term', $result['_undo']['kind'] ?? null);
        $this->assertNotNull(term_exists($termId, 'category'));

        $this->assertNotWPError($this->restore($result));
        $this->assertNull(term_exists($termId, 'category'), 'Created term removed by undo.');
    }

    public function test_content_update_undo_reverts_title(): void
    {
        $postId = self::factory()->post->create(['post_title' => 'Original', 'post_content' => 'Original body']);

        $result = $this->assertAbilitySuccess('gds/content-update', ['type' => 'posts', 'id' => $postId, 'title' => 'Edited']);
        $this->assertSame('restore-post', $result['_undo']['kind'] ?? null);

        $this->assertNotWPError($this->restore($result));
        clean_post_cache($postId);
        $this->assertSame('Original', get_post($postId)->post_title);
    }

    public function test_content_delete_undo_untrashes(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $result = $this->assertAbilitySuccess('gds/content-delete', ['type' => 'posts', 'id' => $postId]);
        $this->assertSame('trash', get_post_status($postId));
        $this->assertSame('untrash', $result['_undo']['kind'] ?? null);

        $this->assertNotWPError($this->restore($result));
        $this->assertNotSame('trash', get_post_status($postId));
    }
}
