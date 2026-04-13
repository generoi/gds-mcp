<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Cross-ability workflow tests that exercise multi-step scenarios.
 *
 * These tests verify that abilities compose correctly — the output of
 * one ability can be used as input to the next.
 */
class WorkflowTest extends AbilityTestCase
{
    // ── Content lifecycle ─────────────────────────────────────────

    public function test_create_read_update_delete_lifecycle(): void
    {
        // 1. Create
        $created = $this->executeAbility('gds/content-create', [
            'type' => 'posts',
            'title' => 'Lifecycle Test Post',
            'content' => '<p>Original content.</p>',
            'status' => 'draft',
        ]);
        $this->assertIsArray($created, 'Create failed: '.($created instanceof \WP_Error ? $created->get_error_message() : ''));
        $id = $created['id'];

        // 2. Read — verify it exists
        $read = $this->assertAbilitySuccess('gds/content-read', [
            'type' => 'posts',
            'id' => $id,
        ]);
        $this->assertSame('Lifecycle Test Post', $read['title']['rendered']);
        $this->assertSame('draft', $read['status']);

        // 3. Update — change title and status
        $updated = $this->executeAbility('gds/content-update', [
            'type' => 'posts',
            'id' => $id,
            'title' => 'Updated Lifecycle Post',
            'status' => 'publish',
        ]);
        $this->assertIsArray($updated);
        $this->assertSame('Updated Lifecycle Post', $updated['title']['rendered']);
        $this->assertSame('publish', $updated['status']);

        // 4. Verify in list
        $list = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'posts',
            'search' => 'Updated Lifecycle Post',
        ]);
        $this->assertGreaterThanOrEqual(1, $list['total']);

        // 5. Delete
        $deleted = $this->executeAbility('gds/content-delete', [
            'type' => 'posts',
            'id' => $id,
            'force' => true,
        ]);
        $this->assertIsArray($deleted);
        $this->assertNull(get_post($id));
    }

    // ── Terms → Content assignment ────────────────────────────────

    public function test_create_term_assign_to_post_list_by_term(): void
    {
        // 1. Create a category
        $term = $this->assertAbilitySuccess('gds/terms-create', [
            'taxonomy' => 'categories',
            'name' => 'Workflow Test Category '.uniqid(),
        ]);
        $termId = $term['id'];

        // 2. Create a post and assign the category via REST
        $post = $this->executeAbility('gds/content-create', [
            'type' => 'posts',
            'title' => 'Categorized Post',
            'content' => '<p>Has a category.</p>',
            'status' => 'publish',
        ]);
        $this->assertIsArray($post);

        // Assign category (REST API uses 'categories' param)
        wp_set_object_terms($post['id'], [$termId], 'category');

        // 3. Verify term is readable
        $readTerm = $this->assertAbilitySuccess('gds/terms-read', [
            'taxonomy' => 'categories',
            'id' => $termId,
        ]);
        $this->assertSame($term['name'], $readTerm['name']);

        // 4. Clean up
        wp_delete_post($post['id'], true);
    }

    // ── Duplicate then modify ─────────────────────────────────────

    public function test_duplicate_and_modify(): void
    {
        // 1. Create original
        $original = $this->executeAbility('gds/content-create', [
            'type' => 'pages',
            'title' => 'Original Page',
            'content' => '<p>Original page content.</p>',
            'status' => 'publish',
        ]);
        $this->assertIsArray($original);

        // 2. Duplicate it
        $duplicate = $this->executeAbility('gds/posts-duplicate', [
            'id' => $original['id'],
        ]);
        $this->assertIsArray($duplicate);
        $this->assertNotEquals($original['id'], $duplicate['id']);

        // 3. Update the duplicate
        $updated = $this->executeAbility('gds/content-update', [
            'type' => 'pages',
            'id' => $duplicate['id'],
            'title' => 'Modified Duplicate',
        ]);
        $this->assertIsArray($updated);
        $this->assertSame('Modified Duplicate', $updated['title']['rendered']);

        // 4. Verify original is unchanged
        $originalCheck = $this->assertAbilitySuccess('gds/content-read', [
            'type' => 'pages',
            'id' => $original['id'],
        ]);
        $this->assertSame('Original Page', $originalCheck['title']['rendered']);

        // Clean up
        wp_delete_post($original['id'], true);
        wp_delete_post($duplicate['id'], true);
    }

    // ── Bulk update + verify ──────────────────────────────────────

    public function test_bulk_update_workflow(): void
    {
        // 1. Create 3 draft posts
        $ids = $this->createPosts(3, [
            'post_type' => 'post',
            'post_status' => 'draft',
        ]);

        // 2. Dry run first
        $dryRun = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => $ids,
            'set_status' => 'publish',
            'dry_run' => true,
        ]);
        $this->assertSame(3, $dryRun['matched']);

        // Verify still drafts
        foreach ($ids as $id) {
            $this->assertSame('draft', get_post_status($id));
        }

        // 3. Apply
        $applied = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => $ids,
            'set_status' => 'publish',
        ]);
        $this->assertSame(3, $applied['updated']);

        // 4. Verify published
        foreach ($ids as $id) {
            $this->assertSame('publish', get_post_status($id));
        }
    }

    // ── Revision workflow ─────────────────────────────────────────

    public function test_edit_list_revisions_restore(): void
    {
        add_filter('wp_revisions_to_keep', fn () => 10);

        // 1. Create a post
        $id = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Version 1 content',
        ]);

        // 2. Update multiple times to create revisions
        wp_update_post(['ID' => $id, 'post_content' => 'Version 2 content']);
        wp_update_post(['ID' => $id, 'post_content' => 'Version 3 content']);

        // 3. List revisions via ability
        $revisions = $this->assertAbilitySuccess('gds/revisions-list', [
            'post_id' => $id,
        ]);
        $this->assertGreaterThanOrEqual(2, count($revisions));

        // 4. Read a specific revision
        $revisionId = $revisions[0]['id'];
        $revision = $this->assertAbilitySuccess('gds/revisions-read', [
            'post_id' => $id,
            'id' => $revisionId,
        ]);
        $this->assertSame($revisionId, $revision['id']);

        // 5. Restore oldest revision
        $oldest = end($revisions);
        $restored = $this->executeAbility('gds/revisions-restore', [
            'id' => $oldest['id'],
        ]);
        $this->assertIsArray($restored);

        // Verify content is restored
        $post = get_post($id);
        $this->assertSame($oldest['content']['rendered'], wpautop($post->post_content));
    }

    // ── Translation workflow ──────────────────────────────────────

    public function test_create_translate_audit(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang not active.');
        }
        if (! PLL()->model->get_language('fi')) {
            $this->markTestSkipped('Finnish language not configured.');
        }

        // 1. Create source post (via factory to ensure Polylang language is set)
        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'English Workflow Post',
            'post_content' => '<p>English content.</p>',
        ]);

        // 2. Audit — should show missing Finnish translation
        $audit1 = $this->assertAbilitySuccess('gds/translations-audit', [
            'post_type' => 'post',
            'lang' => 'fi',
        ]);
        $this->assertGreaterThan(0, $audit1['summary']['total_posts']);

        // 3. Create Finnish translation via ability
        $translation = $this->executeAbility('gds/translations-create', [
            'source_id' => $sourceId,
            'lang' => 'fi',
            'title' => 'Finnish Workflow Post',
            'content' => '<p>Finnish content.</p>',
        ]);
        $this->assertIsArray($translation);
        $this->assertNotEquals($sourceId, $translation['id']);

        // 4. Verify translation is linked
        clean_post_cache($sourceId);
        clean_post_cache($translation['id']);

        $translations = pll_get_post_translations($sourceId);
        $this->assertArrayHasKey('fi', $translations, 'Translation should be linked.');

        // 5. Verify both posts exist
        $sourcePost = get_post($sourceId);
        $translatedPost = get_post($translation['id']);
        $this->assertNotNull($sourcePost);
        $this->assertNotNull($translatedPost);
        $this->assertSame('fi', pll_get_post_language($translation['id']));

        // Clean up
        wp_delete_post($translation['id'], true);
    }

    // ── Help discovery ────────────────────────────────────────────

    public function test_help_lists_all_registered_abilities(): void
    {
        $help = $this->assertAbilitySuccess('gds/help');
        $allAbilityNames = [];
        foreach ($help['groups'] as $group) {
            foreach ($group['abilities'] as $ability) {
                $allAbilityNames[] = $ability['name'];
            }
        }

        // Help should list all registered gds/* abilities (except help itself)
        $abilities = wp_get_abilities();
        foreach ($abilities as $ability) {
            $name = $ability->get_name();
            if (str_starts_with($name, 'gds/') && $name !== 'gds/help') {
                $this->assertContains($name, $allAbilityNames, "Help index missing '{$name}'.");
            }
        }

        // The count should match
        $this->assertSame($help['total'], count($allAbilityNames));
    }
}
