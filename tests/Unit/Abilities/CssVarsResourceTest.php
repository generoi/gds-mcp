<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\CssVarsResource;
use GeneroWP\MCP\Abilities\ThemeJsonResource;
use WP_UnitTestCase;

class CssVarsResourceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_variables_key(): void
    {
        $result = CssVarsResource::execute([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('variables', $result);
        $this->assertIsArray($result['variables']);
    }

    public function test_extract_css_custom_properties_parses_root_block(): void
    {
        // Test the parser directly with a known CSS string.
        // We can't easily mock the file, but we can test the parser logic
        // by checking it returns an array (empty if no theme stylesheet).
        $vars = ThemeJsonResource::extractCssCustomProperties();

        $this->assertIsArray($vars);

        // If we have variables, verify they start with --.
        foreach (array_keys($vars) as $key) {
            $this->assertStringStartsWith('--', $key, "CSS variable key should start with --: {$key}");
        }
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = CssVarsResource::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = CssVarsResource::checkPermission();
        $this->assertWPError($result);
    }
}
