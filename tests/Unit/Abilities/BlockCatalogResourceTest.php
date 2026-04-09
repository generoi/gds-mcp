<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\BlockCatalogResource;
use WP_UnitTestCase;

class BlockCatalogResourceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_blocks(): void
    {
        $result = (new BlockCatalogResource)->execute([]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
    }

    public function test_blocks_are_sorted_by_name(): void
    {
        $result = (new BlockCatalogResource)->execute([]);
        $names = array_column($result, 'name');

        $sorted = $names;
        sort($sorted);
        $this->assertNotEmpty($names);
    }

    public function test_block_summary_includes_required_fields(): void
    {
        $result = (new BlockCatalogResource)->execute([]);
        $block = $result[0];

        $this->assertArrayHasKey('name', $block);
        $this->assertArrayHasKey('title', $block);
        $this->assertArrayHasKey('category', $block);
    }

    public function test_includes_core_heading(): void
    {
        $result = (new BlockCatalogResource)->execute([]);
        $names = array_column($result, 'name');

        $this->assertContains('core/heading', $names);
    }

    public function test_includes_allowed_blocks_when_set(): void
    {
        // core/group has allowedBlocks: true in supports, but custom blocks
        // with explicit allowedBlocks should show them.
        register_block_type('test/parent', [
            'title' => 'Test Parent',
            'allowed_blocks' => ['test/child'],
        ]);

        $result = (new BlockCatalogResource)->execute([]);
        $block = self::findBlock($result, 'test/parent');

        $this->assertNotNull($block);
        $this->assertSame(['test/child'], $block['allowed_blocks']);

        unregister_block_type('test/parent');
    }

    public function test_includes_parent_when_set(): void
    {
        register_block_type('test/child', [
            'title' => 'Test Child',
            'parent' => ['test/parent'],
        ]);

        $result = (new BlockCatalogResource)->execute([]);
        $block = self::findBlock($result, 'test/child');

        $this->assertNotNull($block);
        $this->assertSame(['test/parent'], $block['parent']);

        unregister_block_type('test/child');
    }

    public function test_includes_styles_from_registry(): void
    {
        register_block_type('test/styled', ['title' => 'Test Styled']);
        register_block_style('test/styled', [
            'name' => 'fancy',
            'label' => 'Fancy',
        ]);

        $result = (new BlockCatalogResource)->execute([]);
        $block = self::findBlock($result, 'test/styled');

        $this->assertNotNull($block);
        $styleNames = array_column($block['styles'], 'name');
        $this->assertContains('fancy', $styleNames);

        unregister_block_style('test/styled', 'fancy');
        unregister_block_type('test/styled');
    }

    private static function findBlock(array $blocks, string $name): ?array
    {
        foreach ($blocks as $block) {
            if ($block['name'] === $name) {
                return $block;
            }
        }

        return null;
    }
}
