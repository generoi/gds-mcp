<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/redirects-manage through the Abilities API.
 */
class RedirectsAbilityTest extends AbilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('srm_create_redirect') && ! class_exists('Red_Item') && ! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('No redirect plugin is active.');
        }
    }

    public function test_redirects_ability_registered(): void
    {
        $this->assertAbilityRegistered('gds/redirects-manage');
    }

    public function test_list_redirects(): void
    {
        $result = $this->assertAbilitySuccess('gds/redirects-manage', [
            'action' => 'list',
        ]);

        $this->assertArrayHasKey('provider', $result);
        $this->assertArrayHasKey('redirects', $result);
        $this->assertIsArray($result['redirects']);
    }

    public function test_create_redirect(): void
    {
        $result = $this->assertAbilitySuccess('gds/redirects-manage', [
            'action' => 'create',
            'from' => '/test-redirect-ability-api-'.uniqid(),
            'to' => '/destination',
            'status_code' => 301,
        ]);

        $this->assertArrayHasKey('provider', $result);
        $this->assertArrayHasKey('redirect', $result);
    }

    public function test_create_redirect_requires_from_and_to(): void
    {
        $this->assertAbilityError('gds/redirects-manage', [
            'action' => 'create',
        ]);
    }

    public function test_rejects_invalid_action(): void
    {
        $result = $this->executeAbility('gds/redirects-manage', [
            'action' => 'invalid',
        ]);

        // Should fail at schema validation (enum constraint) or execution
        $this->assertWPError($result);
    }
}
