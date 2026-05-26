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

    public function test_other_abilities_are_left_untouched(): void
    {
        // These enforce caps internally or via their REST controller, or are
        // intentionally public reads — the policy must not override them.
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/content-update'));
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/content-list'));
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/terms-delete'));
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/redirects-manage'));
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/help'));
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/translations-link'));
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

    public function test_apply_capability_leaves_unmapped_abilities_alone(): void
    {
        $args = ['permission_callback' => '__return_true'];
        $out = CapabilityPolicy::applyCapability($args, 'gds/content-list');

        $this->assertSame('__return_true', $out['permission_callback'], 'Unmapped abilities keep their public default.');
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
        // Unmapped abilities still resolve to null through the filter.
        $this->assertNull(CapabilityPolicy::capabilityFor('gds/content-update'));
    }
}
