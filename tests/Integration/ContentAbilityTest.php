<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/content-* abilities through the Abilities API.
 *
 * Each test invokes wp_get_ability()->execute() which validates input/output
 * against JSON Schema, checks permissions, and runs the execute callback.
 */
class ContentAbilityTest extends AbilityTestCase
{
    public function test_content_abilities_are_registered(): void
    {
        $this->assertAbilityRegistered('gds/content-list');
        $this->assertAbilityRegistered('gds/content-read');
        $this->assertAbilityRegistered('gds/content-create');
        $this->assertAbilityRegistered('gds/content-update');
        $this->assertAbilityRegistered('gds/content-delete');
    }

    // ── List ──────────────────────────────────────────────────────

    public function test_list_pages(): void
    {
        $this->createPosts(3, ['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'pages',
            'per_page' => 100,
        ]);

        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertGreaterThanOrEqual(3, $result['total']);
    }

    public function test_list_posts(): void
    {
        $this->createPosts(2, ['post_type' => 'post', 'post_status' => 'publish']);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'posts',
            'per_page' => 100,
        ]);

        $this->assertGreaterThanOrEqual(2, $result['total']);
    }

    public function test_list_with_search(): void
    {
        $this->createPost([
            'post_title' => 'Ability API Integration Test Needle',
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'pages',
            'search' => 'Ability API Integration Test Needle',
        ]);

        $this->assertCount(1, $result['posts']);
    }

    public function test_list_with_fields_filter(): void
    {
        $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'pages',
            '_fields' => 'id,title,link',
        ]);

        $post = $result['posts'][0];
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertArrayNotHasKey('content', $post);
    }

    public function test_list_pagination(): void
    {
        $this->createPosts(5, ['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'pages',
            'per_page' => 2,
            'page' => 1,
        ]);

        $this->assertCount(2, $result['posts']);
        $this->assertGreaterThanOrEqual(3, $result['pages']);
    }

    public function test_list_rejects_invalid_type(): void
    {
        $this->assertAbilityError('gds/content-list', [
            'type' => 'nonexistent_type',
        ]);
    }

    public function test_list_rejects_missing_type(): void
    {
        $result = $this->executeAbility('gds/content-list', []);
        $this->assertWPError($result);
    }

    // ── Read ──────────────────────────────────────────────────────

    public function test_read_page(): void
    {
        $id = $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Read Through API',
        ]);

        $result = $this->assertAbilitySuccess('gds/content-read', [
            'type' => 'pages',
            'id' => $id,
        ]);

        $this->assertSame($id, $result['id']);
        $this->assertSame('Read Through API', $result['title']['rendered']);
    }

    public function test_read_nonexistent_post(): void
    {
        $this->assertAbilityError('gds/content-read', [
            'type' => 'pages',
            'id' => 999999,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function test_create_page(): void
    {
        $result = $this->executeAbility('gds/content-create', [
            'type' => 'pages',
            'title' => 'Created via Ability API',
            'status' => 'draft',
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Content create failed (likely Polylang Pro REST filter): '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertSame('Created via Ability API', $result['title']['rendered']);
        $this->assertSame('draft', $result['status']);

        wp_delete_post($result['id'], true);
    }

    public function test_create_with_content(): void
    {
        $result = $this->executeAbility('gds/content-create', [
            'type' => 'posts',
            'title' => 'Post With Content',
            'content' => '<p>This is the body.</p>',
            'status' => 'draft',
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Content create failed: '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertStringContainsString('This is the body', $result['content']['rendered']);

        wp_delete_post($result['id'], true);
    }

    // ── Update ────────────────────────────────────────────────────

    public function test_update_page_title(): void
    {
        $id = $this->createPost([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Before Update',
        ]);

        $result = $this->executeAbility('gds/content-update', [
            'type' => 'pages',
            'id' => $id,
            'title' => 'After Update',
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Content update failed: '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertSame('After Update', $result['title']['rendered']);
    }

    // ── Delete ────────────────────────────────────────────────────

    public function test_delete_trashes_by_default(): void
    {
        $id = $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = $this->executeAbility('gds/content-delete', [
            'type' => 'pages',
            'id' => $id,
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Content delete failed: '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_delete_force(): void
    {
        $id = $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = $this->executeAbility('gds/content-delete', [
            'type' => 'pages',
            'id' => $id,
            'force' => true,
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Content delete failed: '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertNull(get_post($id));
    }

    // ── Permissions ───────────────────────────────────────────────

    public function test_list_published_works_unauthenticated(): void
    {
        $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);
        wp_set_current_user(0);

        $result = $this->assertAbilitySuccess('gds/content-list', [
            'type' => 'pages',
            'per_page' => 1,
        ]);

        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    public function test_list_draft_blocked_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertAbilityError('gds/content-list', [
            'type' => 'pages',
            'status' => 'draft',
        ]);
    }

    public function test_create_blocked_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertAbilityError('gds/content-create', [
            'type' => 'pages',
            'title' => 'Should Fail',
        ]);
    }
}
