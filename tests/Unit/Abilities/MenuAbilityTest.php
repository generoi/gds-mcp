<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\AddMenuItemAbility;
use GeneroWP\MCP\Abilities\GetMenuAbility;
use GeneroWP\MCP\Abilities\ListMenusAbility;
use GeneroWP\MCP\Abilities\ManageMenuAbility;
use GeneroWP\MCP\Abilities\UpdateMenuItemAbility;
use WP_UnitTestCase;

class MenuAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_list_menus_returns_structure(): void
    {
        wp_create_nav_menu('Test Menu');
        $result = ListMenusAbility::execute([]);

        $this->assertArrayHasKey('locations', $result);
        $this->assertArrayHasKey('menus', $result);
        $this->assertNotEmpty($result['menus']);
    }

    public function test_get_menu_returns_items(): void
    {
        $menuId = wp_create_nav_menu('Menu With Items');
        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Test Item',
            'menu-item-url' => 'https://example.com',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
        ]);

        $result = GetMenuAbility::execute(['menu_id' => $menuId]);

        $this->assertIsArray($result);
        $this->assertSame('Menu With Items', $result['name']);
        $this->assertNotEmpty($result['items']);
        $this->assertSame('Test Item', $result['items'][0]['title']);
    }

    public function test_get_menu_returns_error_for_invalid_id(): void
    {
        $result = GetMenuAbility::execute(['menu_id' => 999999]);
        $this->assertWPError($result);
    }

    public function test_add_menu_item_creates_item(): void
    {
        $menuId = wp_create_nav_menu('Add Item Menu');
        $result = AddMenuItemAbility::execute([
            'menu_id' => $menuId,
            'title' => 'New Item',
            'type' => 'custom',
            'url' => 'https://example.com/new',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('New Item', $result['title']);
        $this->assertSame($menuId, $result['menu_id']);
    }

    public function test_update_menu_item_changes_title(): void
    {
        $menuId = wp_create_nav_menu('Update Test Menu');
        $itemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Original Title',
            'menu-item-url' => 'https://example.com',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
        ]);

        $result = UpdateMenuItemAbility::execute([
            'menu_item_id' => $itemId,
            'title' => 'Updated Title',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Updated Title', $result['title']);
        $this->assertSame($itemId, $result['id']);
    }

    public function test_update_menu_item_changes_position(): void
    {
        $menuId = wp_create_nav_menu('Position Test Menu');
        $itemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Item',
            'menu-item-url' => 'https://example.com',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
            'menu-item-position' => 1,
        ]);

        $result = UpdateMenuItemAbility::execute([
            'menu_item_id' => $itemId,
            'position' => 5,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(5, $result['position']);
    }

    public function test_update_menu_item_returns_error_for_invalid_id(): void
    {
        $result = UpdateMenuItemAbility::execute(['menu_item_id' => 999999]);
        $this->assertWPError($result);
    }

    public function test_create_menu(): void
    {
        $result = ManageMenuAbility::execute([
            'action' => 'create',
            'name' => 'New Test Menu',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('created', $result['action']);
        $this->assertSame('New Test Menu', $result['name']);
        $this->assertGreaterThan(0, $result['menu_id']);

        $menu = wp_get_nav_menu_object($result['menu_id']);
        $this->assertNotFalse($menu);
    }

    public function test_update_menu_name(): void
    {
        $menuId = wp_create_nav_menu('Old Name');
        $result = ManageMenuAbility::execute([
            'action' => 'update',
            'menu_id' => $menuId,
            'name' => 'New Name',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('updated', $result['action']);
        $this->assertSame('New Name', $result['name']);

        $menu = wp_get_nav_menu_object($menuId);
        $this->assertSame('New Name', $menu->name);
    }

    public function test_delete_menu(): void
    {
        $menuId = wp_create_nav_menu('Menu To Delete');
        $result = ManageMenuAbility::execute([
            'action' => 'delete',
            'menu_id' => $menuId,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('deleted', $result['action']);

        $menu = wp_get_nav_menu_object($menuId);
        $this->assertFalse($menu);
    }

    public function test_delete_menu_returns_error_for_invalid_id(): void
    {
        $result = ManageMenuAbility::execute([
            'action' => 'delete',
            'menu_id' => 999999,
        ]);
        $this->assertWPError($result);
    }

    public function test_create_menu_requires_name(): void
    {
        $result = ManageMenuAbility::execute(['action' => 'create']);
        $this->assertWPError($result);
    }

    public function test_menu_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $result = ListMenusAbility::checkPermission();
        $this->assertWPError($result);
    }
}
