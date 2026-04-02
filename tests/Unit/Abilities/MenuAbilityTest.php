<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\AddMenuItemAbility;
use GeneroWP\MCP\Abilities\GetMenuAbility;
use GeneroWP\MCP\Abilities\ListMenusAbility;
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

    public function test_menu_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $result = ListMenusAbility::checkPermission();
        $this->assertWPError($result);
    }
}
