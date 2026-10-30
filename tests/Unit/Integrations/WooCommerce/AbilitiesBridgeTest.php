<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\WooCommerce;

use Automattic\WooCommerce\Internal\MCP\Transport\WooCommerceRestTransport;
use GeneroWP\MCP\Integrations\WooCommerce\AbilitiesBridge;
use WP_UnitTestCase;

class AbilitiesBridgeTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(WooCommerceRestTransport::class) && ! property_exists(WooCommerceRestTransport::class, 'permissions')) {
            $this->markTestSkipped('Real WooCommerce is loaded; the API-key state cannot be faked.');
        }
        require_once dirname(__DIR__, 3).'/Fixtures/WooCommerceRestTransport.php';

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wp_get_current_user()->add_cap('manage_woocommerce');
    }

    protected function tearDown(): void
    {
        WooCommerceRestTransport::$permissions = null;
        parent::tearDown();
    }

    public function test_grants_in_process_callers_by_capability(): void
    {
        $this->assertTrue(AbilitiesBridge::grantInProcessPermission(false, 'POST', null));
    }

    public function test_never_widens_an_api_key_denial(): void
    {
        WooCommerceRestTransport::$permissions = 'read';

        $this->assertFalse(AbilitiesBridge::grantInProcessPermission(false, 'POST', null), 'A read-only key stays read-only.');
        $this->assertTrue(AbilitiesBridge::grantInProcessPermission(true, 'GET', null));
    }
}
