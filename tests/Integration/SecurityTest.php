<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Security tests verifying capability checks on write operations.
 *
 * Tests that subscribers and unauthenticated users cannot perform
 * privileged operations through the Abilities API.
 */
class SecurityTest extends AbilityTestCase
{
    private int $editorId;

    private int $subscriberId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editorId = self::factory()->user->create(['role' => 'editor']);
        $this->subscriberId = self::factory()->user->create(['role' => 'subscriber']);
    }

    // ── PatchBlockAbility ─────────────────────────────────────────

    public function test_patch_block_requires_edit_post_capability(): void
    {
        wp_set_current_user($this->editorId);
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
        ]);

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/blocks-patch', [
            'id' => $postId,
            'block_name' => 'core/paragraph',
            'inner_html' => '<p>Hacked</p>',
        ], 'forbidden');
    }

    // ── DuplicatePostAbility ──────────────────────────────────────

    public function test_duplicate_requires_edit_post_capability(): void
    {
        wp_set_current_user($this->editorId);
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Protected Post',
        ]);

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/posts-duplicate', [
            'id' => $postId,
        ], 'forbidden');
    }

    // ── ManageRevisionsAbility ─────────────────────────────────────

    public function test_restore_revision_requires_edit_post_capability(): void
    {
        add_filter('wp_revisions_to_keep', fn () => 10);

        wp_set_current_user($this->editorId);
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Original',
        ]);
        wp_update_post(['ID' => $postId, 'post_content' => 'Updated']);

        $revisions = wp_get_post_revisions($postId);
        if (empty($revisions)) {
            $this->markTestSkipped('No revisions created.');
        }
        $revisionId = array_key_first($revisions);

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/revisions-restore', [
            'id' => $revisionId,
        ], 'forbidden');
    }

    // ── BulkUpdatePostsAbility ────────────────────────────────────

    public function test_bulk_update_skips_posts_user_cannot_edit(): void
    {
        wp_set_current_user($this->editorId);
        $ids = $this->createPosts(2, [
            'post_type' => 'post',
            'post_status' => 'draft',
        ]);

        wp_set_current_user($this->subscriberId);

        $result = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => $ids,
            'set_status' => 'publish',
        ]);

        // Subscriber can't edit these posts, so 0 should be updated
        $this->assertSame(0, $result['updated']);

        // Posts should still be drafts
        foreach ($ids as $id) {
            $this->assertSame('draft', get_post_status($id));
        }
    }

    public function test_bulk_update_blocks_protected_meta_keys(): void
    {
        wp_set_current_user($this->editorId);
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        $result = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => [$postId],
            'set_meta' => [
                '_wp_page_template' => 'hacked.php',
                'safe_key' => 'safe_value',
            ],
        ]);

        // Protected meta should NOT be set
        $this->assertEmpty(get_post_meta($postId, '_wp_page_template', true));
        // Public meta SHOULD be set
        $this->assertSame('safe_value', get_post_meta($postId, 'safe_key', true));
    }

    // ── Content CRUD (REST-delegated) ─────────────────────────────

    public function test_content_create_blocked_for_subscriber(): void
    {
        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/content-create', [
            'type' => 'posts',
            'title' => 'Should Fail',
        ]);
    }

    public function test_content_delete_blocked_for_subscriber(): void
    {
        wp_set_current_user($this->editorId);
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/content-delete', [
            'type' => 'posts',
            'id' => $postId,
        ]);
    }

    // ── Translation abilities ─────────────────────────────────────

    public function test_create_translation_requires_edit_capability(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang not active.');
        }

        wp_set_current_user($this->editorId);
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'Content.',
        ]);

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/translations-create', [
            'source_id' => $postId,
            'lang' => 'fi',
        ], 'forbidden');
    }

    // ── Redirect abilities ────────────────────────────────────────

    public function test_redirects_blocked_for_subscriber(): void
    {
        if (! function_exists('srm_create_redirect') && ! class_exists('Red_Item') && ! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('No redirect plugin active.');
        }

        wp_set_current_user($this->subscriberId);

        // Both list and create should be blocked
        $this->assertAbilityError('gds/redirects-manage', [
            'action' => 'list',
        ], 'forbidden');

        $this->assertAbilityError('gds/redirects-manage', [
            'action' => 'create',
            'from' => '/test-redirect',
            'to' => '/destination',
        ], 'forbidden');
    }

    // ── Cache clearing ────────────────────────────────────────────

    public function test_cache_clear_requires_manage_options(): void
    {
        if (! class_exists(\Genero\Sage\CacheTags\CacheTags::class)) {
            $this->markTestSkipped('sage-cachetags not active.');
        }

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/cache-clear', [
            'type' => 'flush',
        ], 'forbidden');
    }

    // ── Activity log ──────────────────────────────────────────────

    public function test_activity_log_requires_manage_options(): void
    {
        if (! class_exists(\WP_Stream\Plugin::class)) {
            $this->markTestSkipped('Stream not active.');
        }

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/activity-query', [
            'per_page' => 1,
        ], 'forbidden');
    }

    // ── String translations ───────────────────────────────────────

    public function test_update_string_translation_requires_manage_options(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang not active.');
        }

        wp_set_current_user($this->subscriberId);

        $this->assertAbilityError('gds/strings-update', [
            'string' => 'test',
            'lang' => 'fi',
            'translation' => 'testi',
        ], 'forbidden');
    }

    // ── Read operations should still work ─────────────────────────

    public function test_subscriber_can_list_published_content(): void
    {
        wp_set_current_user($this->editorId);
        $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);

        wp_set_current_user($this->subscriberId);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'posts',
            'per_page' => 1,
        ]);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    public function test_subscriber_can_use_help(): void
    {
        wp_set_current_user($this->subscriberId);

        $result = $this->assertAbilitySuccess('gds/help');
        $this->assertGreaterThan(0, $result['total']);
    }

    public function test_unauthenticated_can_list_published_content(): void
    {
        $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);
        wp_set_current_user(0);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'posts',
            'per_page' => 1,
        ]);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }
}
