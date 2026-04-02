<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ThemeJsonResource;
use WP_UnitTestCase;

class ThemeJsonResourceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_array(): void
    {
        $result = ThemeJsonResource::execute([]);

        $this->assertIsArray($result);
    }

    public function test_execute_includes_layout_when_defined(): void
    {
        $result = ThemeJsonResource::execute([]);

        // theme.json may or may not define layout in wp-env's default theme.
        // Just verify the structure is correct if present.
        if (isset($result['layout'])) {
            $this->assertArrayHasKey('contentSize', $result['layout']);
            $this->assertArrayHasKey('wideSize', $result['layout']);
        } else {
            $this->assertIsArray($result);
        }
    }

    public function test_execute_colors_have_expected_structure(): void
    {
        $result = ThemeJsonResource::execute([]);

        if (isset($result['colors'])) {
            foreach ($result['colors'] as $color) {
                $this->assertArrayHasKey('slug', $color);
                $this->assertArrayHasKey('name', $color);
                $this->assertArrayHasKey('color', $color);
            }
        } else {
            $this->assertIsArray($result);
        }
    }

    public function test_execute_font_sizes_have_expected_structure(): void
    {
        $result = ThemeJsonResource::execute([]);

        if (isset($result['font_sizes'])) {
            foreach ($result['font_sizes'] as $size) {
                $this->assertArrayHasKey('slug', $size);
                $this->assertArrayHasKey('name', $size);
                $this->assertArrayHasKey('size', $size);
            }
        } else {
            $this->assertIsArray($result);
        }
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = ThemeJsonResource::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = ThemeJsonResource::checkPermission();
        $this->assertWPError($result);
    }
}
