<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\PostTypeAbility;
use GeneroWP\MCP\Abilities\TaxonomyAbility;
use GeneroWP\MCP\Tests\TestCase;

class DeleteAbilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    // ── Posts ─────────────────────────────────────────────────────

    public function test_delete_post_trashes_by_default(): void
    {
        $postId = $this->createPost(['post_status' => 'publish', 'post_title' => 'To Trash']);
        $ability = new PostTypeAbility('post', '/wp/v2/posts', 'posts', 'Posts');

        $result = $ability->executeDelete(['id' => $postId]);

        $this->assertIsArray($result);
        $this->assertSame('trash', get_post_status($postId));
    }

    public function test_delete_post_force_deletes(): void
    {
        $postId = $this->createPost(['post_status' => 'publish']);
        $ability = new PostTypeAbility('post', '/wp/v2/posts', 'posts', 'Posts');

        $result = $ability->executeDelete(['id' => $postId, 'force' => true]);

        $this->assertIsArray($result);
        $this->assertTrue($result['deleted'] ?? false);
        $this->assertNull(get_post($postId));
    }

    public function test_delete_post_returns_error_for_missing(): void
    {
        $ability = new PostTypeAbility('post', '/wp/v2/posts', 'posts', 'Posts');
        $result = $ability->executeDelete(['id' => 999999]);
        $this->assertWPError($result);
    }

    // ── Terms ────────────────────────────────────────────────────

    public function test_delete_term(): void
    {
        $term = wp_insert_term('Deletable', 'category');
        $ability = new TaxonomyAbility('category', '/wp/v2/categories', 'categories', 'Categories');

        $result = $ability->executeDelete(['id' => $term['term_id'], 'force' => true]);

        $this->assertIsArray($result);
        $this->assertTrue($result['deleted'] ?? false);
    }

    // ── Menu Items ───────────────────────────────────────────────

    public function test_delete_menu_item(): void
    {
        $menuId = wp_create_nav_menu('Delete Test Menu');
        $itemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Remove Me',
            'menu-item-url' => 'https://example.com',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
        ]);

        $ability = new PostTypeAbility('nav_menu_item', '/wp/v2/menu-items', 'menu-items', 'Menu Items');
        $result = $ability->executeDelete(['id' => $itemId, 'force' => true]);

        $this->assertIsArray($result);
        $this->assertTrue($result['deleted'] ?? false);
    }
}
