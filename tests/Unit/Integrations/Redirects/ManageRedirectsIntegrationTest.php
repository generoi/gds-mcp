<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Redirects;

use GeneroWP\MCP\Integrations\Redirects\ManageRedirectsAbility;
use WP_UnitTestCase;

class ManageRedirectsIntegrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (! function_exists('srm_create_redirect') && ! class_exists('Red_Item') && ! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('No redirect plugin active.');
        }
    }

    public function test_create_and_list_redirect(): void
    {
        $createResult = ManageRedirectsAbility::execute([
            'action' => 'create',
            'from' => '/test-old-page-'.uniqid(),
            'to' => '/test-new-page',
            'status_code' => 301,
            'notes' => 'Test redirect',
        ]);

        $this->assertIsArray($createResult);
        $this->assertArrayHasKey('provider', $createResult);
        $this->assertArrayHasKey('redirect', $createResult);
        $this->assertSame(301, $createResult['redirect']['status_code']);

        // Verify it shows up in list.
        $listResult = ManageRedirectsAbility::execute(['action' => 'list']);

        $this->assertIsArray($listResult);
        $this->assertNotEmpty($listResult['redirects']);

        $ids = array_column($listResult['redirects'], 'id');
        $this->assertContains($createResult['redirect']['id'], $ids);
    }

    public function test_create_validates_from_path(): void
    {
        $result = ManageRedirectsAbility::execute([
            'action' => 'create',
            'from' => 'not-a-path',
            'to' => '/destination',
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_from', $result->get_error_code());
    }

    public function test_create_validates_to_url(): void
    {
        $result = ManageRedirectsAbility::execute([
            'action' => 'create',
            'from' => '/old-page',
            'to' => 'not a url',
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_to', $result->get_error_code());
    }
}
