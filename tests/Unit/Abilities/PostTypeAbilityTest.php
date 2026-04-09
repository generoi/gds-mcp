<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\PostTypeAbility;
use GeneroWP\MCP\Tests\TestCase;

class PostTypeAbilityTest extends TestCase
{
    private PostTypeAbility $pages;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->pages = new PostTypeAbility('page', '/wp/v2/pages', 'pages', 'Pages');
    }

    // ── List ──────────────────────────────────────────────────────

    public function test_list_returns_posts(): void
    {
        $this->createPosts(3, ['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeList(['per_page' => 100]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertGreaterThanOrEqual(3, $result['total']);
    }

    public function test_list_search(): void
    {
        $this->createPost(['post_title' => 'Unique Searchable Page', 'post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeList(['search' => 'Unique Searchable']);

        $this->assertCount(1, $result['posts']);
    }

    public function test_list_pagination(): void
    {
        $this->createPosts(5, ['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeList(['per_page' => 2, 'page' => 1]);

        $this->assertCount(2, $result['posts']);
        $this->assertGreaterThanOrEqual(3, $result['pages']);
    }

    public function test_list_fields_filter(): void
    {
        $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeList(['_fields' => 'id,title,link']);

        $post = $result['posts'][0];
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertArrayHasKey('link', $post);
        $this->assertArrayNotHasKey('content', $post);
    }

    public function test_list_returns_rest_response_shape(): void
    {
        $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeList(['per_page' => 1]);
        $post = $result['posts'][0];

        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('type', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertIsArray($post['title']);
        $this->assertArrayHasKey('rendered', $post['title']);
        $this->assertArrayHasKey('status', $post);
        $this->assertArrayHasKey('link', $post);
    }

    // ── Read ─────────────────────────────────────────────────────

    public function test_read_returns_post(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Read Test']);

        $result = $this->pages->executeRead(['id' => $id]);

        $this->assertIsArray($result);
        $this->assertSame($id, $result['id']);
        $this->assertSame('Read Test', $result['title']['rendered']);
    }

    public function test_read_not_found(): void
    {
        $result = $this->pages->executeRead(['id' => 999999]);
        $this->assertWPError($result);
    }

    // ── Create ───────────────────────────────────────────────────

    public function test_create_post(): void
    {
        $result = $this->pages->executeCreate([
            'title' => 'Created via REST',
            'status' => 'draft',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Created via REST', $result['title']['rendered']);
        $this->assertSame('draft', $result['status']);

        // Cleanup
        wp_delete_post($result['id'], true);
    }

    // ── Update ───────────────────────────────────────────────────

    public function test_update_post(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Before']);

        $result = $this->pages->executeUpdate(['id' => $id, 'title' => 'After']);

        $this->assertIsArray($result);
        $this->assertSame('After', $result['title']['rendered']);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function test_delete_trashes_by_default(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeDelete(['id' => $id]);

        $this->assertIsArray($result);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_delete_force(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->pages->executeDelete(['id' => $id, 'force' => true]);

        $this->assertIsArray($result);
        $this->assertNull(get_post($id));
    }

    // ── Permissions (REST API delegation) ─────────────────────────

    public function test_list_published_works_unauthenticated(): void
    {
        $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);
        wp_set_current_user(0);

        $result = $this->pages->executeList(['per_page' => 1]);

        // Published content is publicly readable via REST
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    public function test_list_draft_blocked_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = $this->pages->executeList(['status' => 'draft']);

        $this->assertWPError($result);
    }

    public function test_create_blocked_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = $this->pages->executeCreate(['title' => 'Should Fail']);

        $this->assertWPError($result);
    }

    public function test_delete_blocked_for_subscriber(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = $this->pages->executeDelete(['id' => $id]);

        $this->assertWPError($result);
    }
}
