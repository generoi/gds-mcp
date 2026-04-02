<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\DeleteMediaAbility;
use GeneroWP\MCP\Abilities\DeletePostAbility;
use GeneroWP\MCP\Abilities\DeleteTermAbility;
use GeneroWP\MCP\Abilities\RemoveMenuItemAbility;
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

        $result = DeletePostAbility::execute(['post_id' => $postId]);

        $this->assertIsArray($result);
        $this->assertTrue($result['trashed']);
        $this->assertFalse($result['deleted']);
        $this->assertSame('trash', get_post_status($postId));
    }

    public function test_delete_post_force_deletes(): void
    {
        $postId = $this->createPost(['post_status' => 'publish']);

        $result = DeletePostAbility::execute(['post_id' => $postId, 'force' => true]);

        $this->assertIsArray($result);
        $this->assertTrue($result['deleted']);
        $this->assertNull(get_post($postId));
    }

    public function test_delete_post_returns_error_for_missing(): void
    {
        $result = DeletePostAbility::execute(['post_id' => 999999]);
        $this->assertWPError($result);
    }

    public function test_delete_post_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = DeletePostAbility::checkPermission(['post_id' => 1]);
        $this->assertWPError($result);
    }

    // ── Terms ────────────────────────────────────────────────────

    public function test_delete_term(): void
    {
        $term = wp_insert_term('Deletable', 'category');

        $result = DeleteTermAbility::execute([
            'term_id' => $term['term_id'],
            'taxonomy' => 'category',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['deleted']);
        $this->assertSame('Deletable', $result['name']);
        $this->assertNull(get_term($term['term_id'], 'category'));
    }

    public function test_delete_term_invalid_taxonomy(): void
    {
        $result = DeleteTermAbility::execute(['term_id' => 1, 'taxonomy' => 'nonexistent']);
        $this->assertWPError($result);
    }

    public function test_delete_term_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = DeleteTermAbility::checkPermission();
        $this->assertWPError($result);
    }

    // ── Media ────────────────────────────────────────────────────

    public function test_delete_media_returns_error_for_non_attachment(): void
    {
        $postId = $this->createPost();
        $result = DeleteMediaAbility::execute(['attachment_id' => $postId]);
        $this->assertWPError($result);
        $this->assertSame('attachment_not_found', $result->get_error_code());
    }

    public function test_delete_media_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = DeleteMediaAbility::checkPermission(['attachment_id' => 1]);
        $this->assertWPError($result);
    }

    // ── Menu Items ───────────────────────────────────────────────

    public function test_remove_menu_item(): void
    {
        $menuId = wp_create_nav_menu('Delete Test Menu');
        $itemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Remove Me',
            'menu-item-url' => 'https://example.com',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
        ]);

        $result = RemoveMenuItemAbility::execute(['menu_item_id' => $itemId]);

        $this->assertIsArray($result);
        $this->assertTrue($result['deleted']);
        $this->assertSame('Remove Me', $result['title']);
    }

    public function test_remove_menu_item_not_found(): void
    {
        $result = RemoveMenuItemAbility::execute(['menu_item_id' => 999999]);
        $this->assertWPError($result);
    }

    public function test_remove_menu_item_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $result = RemoveMenuItemAbility::checkPermission();
        $this->assertWPError($result);
    }
}
