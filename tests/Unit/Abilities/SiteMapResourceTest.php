<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\SiteMapResource;
use GeneroWP\MCP\Tests\TestCase;

class SiteMapResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_returns_structure(): void
    {
        $result = (new SiteMapResource)->execute([]);

        $this->assertArrayHasKey('menu', $result);
        $this->assertArrayHasKey('disconnected', $result);
    }

    public function test_disconnected_pages_found(): void
    {
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Orphan Page',
        ]);

        $result = (new SiteMapResource)->execute([]);

        $titles = array_column($result['disconnected'], 'title');
        $this->assertContains('Orphan Page', $titles);
    }

    public function test_menu_items_have_expected_fields(): void
    {
        $menuId = wp_create_nav_menu('Test Primary');
        $pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Menu Page',
        ]);

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-object-id' => $pageId,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
            'menu-item-title' => 'Menu Page',
        ]);

        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menuId]);

        $result = (new SiteMapResource)->execute([]);

        $this->assertNotNull($result['menu']);
        $this->assertSame('Test Primary', $result['menu']['name']);
        $this->assertNotEmpty($result['menu']['items']);

        $item = $result['menu']['items'][0];
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('url', $item);
        $this->assertArrayHasKey('post_id', $item);
        $this->assertSame($pageId, $item['post_id']);
    }

    public function test_menu_pages_excluded_from_disconnected(): void
    {
        $menuId = wp_create_nav_menu('Test Nav');
        $pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Connected Page',
        ]);

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-object-id' => $pageId,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
            'menu-item-title' => 'Connected Page',
        ]);

        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menuId]);

        $result = (new SiteMapResource)->execute([]);

        $disconnectedIds = array_column($result['disconnected'], 'post_id');
        $this->assertNotContains($pageId, $disconnectedIds);
    }

    public function test_disconnected_pages_report_total_and_truncation(): void
    {
        self::factory()->post->create_many(101, ['post_type' => 'page', 'post_status' => 'publish']);

        $result = (new SiteMapResource)->execute([]);

        $this->assertCount(100, $result['disconnected']);
        $this->assertGreaterThanOrEqual(101, $result['disconnected_total']);
        $this->assertTrue($result['truncated']);
    }

    public function test_menu_and_disconnected_pages_come_from_one_language(): void
    {
        if (! function_exists('pll_set_post_language') || ! function_exists('PLL')) {
            $this->markTestSkipped('Polylang not active in this environment.');
        }
        $model = PLL()->model;
        $model->clean_languages_cache();
        foreach ([
            ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0],
            ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1],
        ] as $lang) {
            if (! $model->get_language($lang['slug'])) {
                $model->add_language($lang);
            }
        }
        $model->update_default_lang('en');
        $model->clean_languages_cache();

        $enPage = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'English page']);
        $fiPage = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Finnish page']);
        pll_set_post_language($enPage, 'en');
        pll_set_post_language($fiPage, 'fi');

        $enMenu = wp_create_nav_menu('English menu');
        $fiMenu = wp_create_nav_menu('Finnish menu');
        $navMenus = PLL()->options['nav_menus'];
        PLL()->options['nav_menus'] = [get_stylesheet() => ['primary_navigation' => ['en' => $enMenu, 'fi' => $fiMenu]]];
        $unassigned = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'No language page']);
        wp_delete_object_term_relationships($unassigned, 'language');

        try {
            $result = (new SiteMapResource)->execute(['lang' => 'fi']);
        } finally {
            PLL()->options['nav_menus'] = $navMenus;
        }

        $this->assertSame('fi', $result['language']);
        $this->assertSame('Finnish menu', $result['menu']['name']);
        $titles = array_column($result['disconnected'], 'title');
        $this->assertContains('Finnish page', $titles);
        $this->assertNotContains('English page', $titles);
        $this->assertContains('No language page', $titles, 'A page with no language is not hidden.');

        $this->assertWPError((new SiteMapResource)->execute(['lang' => 'xx']));
    }

    public function test_hierarchical_menu(): void
    {
        $menuId = wp_create_nav_menu('Hierarchical');

        $parentItemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Parent',
            'menu-item-url' => '/parent',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
        ]);

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Child',
            'menu-item-url' => '/child',
            'menu-item-type' => 'custom',
            'menu-item-status' => 'publish',
            'menu-item-parent-id' => $parentItemId,
        ]);

        set_theme_mod('nav_menu_locations', ['primary_navigation' => $menuId]);

        $result = (new SiteMapResource)->execute([]);

        $parent = $result['menu']['items'][0];
        $this->assertSame('Parent', $parent['title']);
        $this->assertNotEmpty($parent['children']);
        $this->assertSame('Child', $parent['children'][0]['title']);
    }
}
