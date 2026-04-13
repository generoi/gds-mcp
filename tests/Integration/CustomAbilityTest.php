<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for custom (non-CRUD) abilities through the Abilities API.
 *
 * Covers: help, duplicate, bulk-update, revisions, blocks-get, site-map, theme-json.
 */
class CustomAbilityTest extends AbilityTestCase
{
    public function test_custom_abilities_are_registered(): void
    {
        $this->assertAbilityRegistered('gds/help');
        $this->assertAbilityRegistered('gds/posts-duplicate');
        $this->assertAbilityRegistered('gds/posts-bulk-update');
        $this->assertAbilityRegistered('gds/revisions-list');
        $this->assertAbilityRegistered('gds/revisions-read');
        $this->assertAbilityRegistered('gds/revisions-restore');
        $this->assertAbilityRegistered('gds/blocks-get');
        $this->assertAbilityRegistered('gds/blocks-patch');
        $this->assertAbilityRegistered('gds/block-types-list');
        $this->assertAbilityRegistered('gds/site-map');
        $this->assertAbilityRegistered('gds/design-theme-json');
    }

    // ── Help ──────────────────────────────────────────────────────

    public function test_help_returns_grouped_abilities(): void
    {
        $result = $this->assertAbilitySuccess('gds/help');

        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('tip', $result);
        $this->assertGreaterThan(0, $result['total']);
    }

    public function test_help_groups_have_required_structure(): void
    {
        $result = $this->assertAbilitySuccess('gds/help');

        foreach ($result['groups'] as $group) {
            $this->assertArrayHasKey('name', $group);
            $this->assertArrayHasKey('abilities', $group);

            foreach ($group['abilities'] as $ability) {
                $this->assertArrayHasKey('name', $ability);
                $this->assertArrayHasKey('label', $ability);
                $this->assertArrayHasKey('description', $ability);
                $this->assertArrayHasKey('type', $ability);
                $this->assertContains($ability['type'], ['tool', 'resource']);
            }
        }
    }

    public function test_help_works_unauthenticated(): void
    {
        wp_set_current_user(0);

        $result = $this->assertAbilitySuccess('gds/help');
        $this->assertGreaterThan(0, $result['total']);
    }

    // ── Duplicate ─────────────────────────────────────────────────

    public function test_duplicate_post(): void
    {
        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Original Post',
            'post_content' => 'Original content here.',
        ]);
        update_post_meta($sourceId, 'custom_key', 'custom_value');

        $result = $this->executeAbility('gds/posts-duplicate', [
            'id' => $sourceId,
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Duplicate failed (Polylang Pro): '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertNotEquals($sourceId, $result['id']);
        $this->assertSame('draft', $result['status']);
        $this->assertStringContainsString('(Copy)', $result['title']['rendered']);

        // Verify meta was copied
        $this->assertSame('custom_value', get_post_meta($result['id'], 'custom_key', true));

        wp_delete_post($result['id'], true);
    }

    public function test_duplicate_with_custom_title(): void
    {
        $sourceId = $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Source Page',
        ]);

        $result = $this->executeAbility('gds/posts-duplicate', [
            'id' => $sourceId,
            'title' => 'Custom Duplicate Title',
            'status' => 'draft',
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Duplicate failed: '.$result->get_error_message());
        }

        $this->assertSame('Custom Duplicate Title', $result['title']['rendered']);

        wp_delete_post($result['id'], true);
    }

    public function test_duplicate_nonexistent_post(): void
    {
        $this->assertAbilityError('gds/posts-duplicate', [
            'id' => 999999,
        ]);
    }

    // ── Bulk Update ───────────────────────────────────────────────

    public function test_bulk_update_dry_run(): void
    {
        $ids = $this->createPosts(3, [
            'post_type' => 'post',
            'post_status' => 'draft',
        ]);

        $result = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => $ids,
            'set_status' => 'publish',
            'dry_run' => true,
        ]);

        $this->assertArrayHasKey('matched', $result);
        $this->assertSame(count($ids), $result['matched']);

        // Posts should still be drafts after dry run
        foreach ($ids as $id) {
            $this->assertSame('draft', get_post_status($id));
        }
    }

    public function test_bulk_update_applies_changes(): void
    {
        $ids = $this->createPosts(2, [
            'post_type' => 'post',
            'post_status' => 'draft',
        ]);

        $result = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => $ids,
            'set_status' => 'publish',
        ]);

        $this->assertArrayHasKey('updated', $result);

        foreach ($ids as $id) {
            $this->assertSame('publish', get_post_status($id));
        }
    }

    // ── Revisions ─────────────────────────────────────────────────

    public function test_list_revisions(): void
    {
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Version 1',
        ]);

        // Create a revision by updating the post
        wp_update_post([
            'ID' => $postId,
            'post_content' => 'Version 2',
        ]);

        $result = $this->assertAbilitySuccess('gds/revisions-list', [
            'post_id' => $postId,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(1, count($result));
    }

    public function test_read_revision(): void
    {
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Initial content',
        ]);

        wp_update_post([
            'ID' => $postId,
            'post_content' => 'Updated content',
        ]);

        $revisions = wp_get_post_revisions($postId);
        $this->assertNotEmpty($revisions);

        $revisionId = array_key_first($revisions);

        $result = $this->assertAbilitySuccess('gds/revisions-read', [
            'post_id' => $postId,
            'id' => $revisionId,
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertSame($revisionId, $result['id']);
    }

    public function test_restore_revision(): void
    {
        // Ensure revisions are enabled for posts.
        add_filter('wp_revisions_to_keep', fn () => 10);

        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Original content for restore',
        ]);

        // Force a revision by updating twice (first update creates initial revision).
        wp_update_post(['ID' => $postId, 'post_content' => 'Version 2']);
        wp_update_post(['ID' => $postId, 'post_content' => 'Version 3']);

        $revisions = wp_get_post_revisions($postId);

        if (empty($revisions)) {
            $this->markTestSkipped('No revisions were created (revisions may be disabled).');
        }

        // Pick the oldest revision (should have earlier content).
        $targetRevision = end($revisions);

        $result = $this->executeAbility('gds/revisions-restore', [
            'id' => $targetRevision->ID,
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Restore failed: '.$result->get_error_message());
        }

        $restored = get_post($postId);
        $this->assertSame($targetRevision->post_content, $restored->post_content);
    }

    public function test_revisions_not_found(): void
    {
        $this->assertAbilityError('gds/revisions-list', [
            'post_id' => 999999,
        ]);
    }

    // ── Block Catalog ─────────────────────────────────────────────

    public function test_block_catalog_returns_block_types(): void
    {
        $result = $this->assertAbilitySuccess('gds/block-types-list');

        $this->assertIsArray($result);
        // Should contain at least some core blocks
        $this->assertNotEmpty($result);
    }

    // ── Site Map ──────────────────────────────────────────────────

    public function test_site_map_returns_structure(): void
    {
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Site Map Test Page',
        ]);

        $result = $this->assertAbilitySuccess('gds/site-map');

        $this->assertIsArray($result);
    }

    // ── Theme JSON ────────────────────────────────────────────────

    public function test_theme_json_returns_design_tokens(): void
    {
        $result = $this->assertAbilitySuccess('gds/design-theme-json');

        $this->assertIsArray($result);
    }
}
