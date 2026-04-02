<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Redirects;

use GeneroWP\MCP\Integrations\Redirects\ManageRedirectsAbility;
use WP_UnitTestCase;

class ManageRedirectsAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_list_returns_provider(): void
    {
        if (! function_exists('srm_create_redirect') && ! class_exists('Red_Item') && ! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('No redirect plugin active.');
        }

        $result = ManageRedirectsAbility::execute(['action' => 'list']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('provider', $result);
        $this->assertArrayHasKey('redirects', $result);
    }

    public function test_execute_create_requires_from_and_to(): void
    {
        if (! function_exists('srm_create_redirect') && ! class_exists('Red_Item') && ! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('No redirect plugin active.');
        }

        $result = ManageRedirectsAbility::execute([
            'action' => 'create',
            'from' => '/old-page',
        ]);

        $this->assertWPError($result);
        $this->assertSame('missing_fields', $result->get_error_code());
    }

    public function test_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $result = ManageRedirectsAbility::checkPermission();
        $this->assertWPError($result);
    }
}
