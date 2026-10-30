<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\CapabilityPolicy;
use WP_UnitTestCase;

class CapabilityPolicyTest extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('gds-mcp/ability_capability');
        parent::tearDown();
    }

    public function test_gravity_forms_abilities_require_manage_options(): void
    {
        $this->assertSame('manage_options', CapabilityPolicy::capabilityFor('gds/forms-update'));
        $this->assertSame('manage_options', CapabilityPolicy::capabilityFor('gds/forms-entries'));
        $this->assertSame('manage_options', CapabilityPolicy::capabilityFor('gds/feeds-update'));
        $this->assertSame('manage_options', CapabilityPolicy::capabilityFor('gds/feeds-delete'));
    }

    public function test_nav_menu_abilities_require_edit_theme_options(): void
    {
        // nav_menu_item is an edit_theme_options object in core, and these
        // abilities either check nothing of their own or write before their
        // REST controller checks.
        foreach (['create', 'update', 'delete', 'move', 'reorder'] as $operation) {
            $this->assertSame(
                'edit_theme_options',
                CapabilityPolicy::capabilityFor('gds/nav-menu-items-'.$operation),
                $operation.' must be gated'
            );
        }
    }

    public function test_coarse_capabilities_are_checked_before_execute(): void
    {
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/posts-duplicate'));
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/blocks-patch'));
        $this->assertSame('upload_files', CapabilityPolicy::capabilityFor('gds/media-upload'));
        $this->assertSame('manage_options', CapabilityPolicy::capabilityFor('gds/cache-clear'));
        $this->assertSame('manage_options', CapabilityPolicy::capabilityFor('gds/redirects-manage'));

        // Registered abilities carry it as their permission_callback.
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertTrue(wp_get_ability('gds/posts-duplicate')->check_permissions(['id' => 1]));
        $this->assertFalse(wp_get_ability('gds/media-upload')->check_permissions(['filename' => 'a.png']), 'Contributors cannot upload files.');
    }

    public function test_other_abilities_need_edit_posts(): void
    {
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/content-update'));
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/content-list'));
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/blocks-get'));
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/help'));

        // The MCP floor, when a site raises it.
        add_filter('gds-mcp/oauth_capability', fn () => 'publish_pages');
        $this->assertSame('publish_pages', CapabilityPolicy::capabilityFor('gds/content-list'));
        remove_all_filters('gds-mcp/oauth_capability');
    }

    public function test_an_empty_mcp_floor_still_gates_unmapped_abilities(): void
    {
        add_filter('gds-mcp/oauth_capability', '__return_empty_string');
        $out = CapabilityPolicy::applyCapability(['permission_callback' => '__return_true'], 'gds/content-list');
        remove_all_filters('gds-mcp/oauth_capability');

        $this->assertNotSame('__return_true', $out['permission_callback']);
        wp_set_current_user(0);
        $this->assertFalse((bool) ($out['permission_callback'])());
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertTrue((bool) ($out['permission_callback'])());
    }

    public function test_anonymous_visitors_cannot_run_abilities_over_rest(): void
    {
        wp_set_current_user(0);
        $request = new \WP_REST_Request('GET', '/wp-abilities/v1/abilities/gds/blocks-get/run');
        $request->set_query_params(['input' => ['name' => 'core/paragraph']]);

        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('rest_ability_cannot_execute', $response->as_error()->get_error_code());
    }

    public function test_apply_capability_replaces_default_callback_for_forms(): void
    {
        $args = ['permission_callback' => '__return_true'];
        $out = CapabilityPolicy::applyCapability($args, 'gds/forms-update');

        $this->assertIsCallable($out['permission_callback']);
        $this->assertNotSame('__return_true', $out['permission_callback']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertFalse((bool) ($out['permission_callback'])(), 'Editor must be blocked from forms-update.');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue((bool) ($out['permission_callback'])(), 'Admin must pass forms-update.');
    }

    public function test_apply_capability_gates_unmapped_abilities_at_edit_posts(): void
    {
        $out = CapabilityPolicy::applyCapability(['permission_callback' => '__return_true'], 'gds/content-list');

        wp_set_current_user(0);
        $this->assertFalse((bool) ($out['permission_callback'])());
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertTrue((bool) ($out['permission_callback'])());
    }

    public function test_apply_capability_never_loosens_an_existing_check(): void
    {
        $existing = [self::class, 'tearDown']; // any non-default callable
        $args = ['permission_callback' => $existing];

        $out = CapabilityPolicy::applyCapability($args, 'gds/forms-update');

        $this->assertSame($existing, $out['permission_callback'], 'A declared callback must be left untouched.');
    }

    public function test_non_gds_abilities_are_ignored(): void
    {
        $args = ['permission_callback' => '__return_true'];
        $out = CapabilityPolicy::applyCapability($args, 'woocommerce/products-list');

        $this->assertSame('__return_true', $out['permission_callback']);
    }

    public function test_capability_is_filterable(): void
    {
        add_filter('gds-mcp/ability_capability', function ($cap, $name) {
            return $name === 'gds/forms-update' ? 'edit_posts' : $cap;
        }, 10, 2);

        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/forms-update'));
        $this->assertSame('edit_posts', CapabilityPolicy::capabilityFor('gds/content-update'));
    }
}
