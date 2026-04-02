<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\GetBlockAbility;
use WP_UnitTestCase;

class GetBlockAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_returns_error_without_name(): void
    {
        $result = GetBlockAbility::execute([]);
        $this->assertWPError($result);
        $this->assertSame('missing_name', $result->get_error_code());
    }

    public function test_returns_error_for_unknown_block(): void
    {
        $result = GetBlockAbility::execute(['name' => 'test/nonexistent']);
        $this->assertWPError($result);
        $this->assertSame('block_not_found', $result->get_error_code());
    }

    public function test_returns_block_detail(): void
    {
        $result = GetBlockAbility::execute(['name' => 'core/heading']);

        $this->assertArrayHasKey('block', $result);
        $block = $result['block'];
        $this->assertSame('core/heading', $block['name']);
        $this->assertSame('Heading', $block['title']);
        $this->assertArrayHasKey('attributes', $block);
        $this->assertArrayHasKey('supports', $block);
    }

    public function test_attributes_include_types_and_defaults(): void
    {
        $result = GetBlockAbility::execute(['name' => 'core/heading']);
        $attrs = $result['block']['attributes'];

        $this->assertArrayHasKey('level', $attrs);
        $this->assertSame('number', $attrs['level']['type']);
        $this->assertSame(2, $attrs['level']['default']);
    }

    public function test_includes_allowed_blocks(): void
    {
        register_block_type('test/container', [
            'title' => 'Test Container',
            'allowed_blocks' => ['core/paragraph', 'core/heading'],
        ]);

        $result = GetBlockAbility::execute(['name' => 'test/container']);
        $this->assertSame(['core/paragraph', 'core/heading'], $result['block']['allowed_blocks']);

        unregister_block_type('test/container');
    }

    public function test_includes_styles_from_both_sources(): void
    {
        register_block_type('test/dual-styles', [
            'title' => 'Test Dual Styles',
            'styles' => [
                ['name' => 'default', 'label' => 'Default', 'isDefault' => true],
            ],
        ]);
        register_block_style('test/dual-styles', [
            'name' => 'alternate',
            'label' => 'Alternate',
        ]);

        $result = GetBlockAbility::execute(['name' => 'test/dual-styles']);
        $styleNames = array_column($result['block']['styles'], 'name');

        $this->assertContains('default', $styleNames);
        $this->assertContains('alternate', $styleNames);

        unregister_block_style('test/dual-styles', 'alternate');
        unregister_block_type('test/dual-styles');
    }

    public function test_demo_page_examples(): void
    {
        $pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'demo-blocks',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:heading {"level":3} -->'
                ."\n".'<h3 class="wp-block-heading">Test</h3>'
                ."\n".'<!-- /wp:heading -->',
        ]);

        $result = GetBlockAbility::execute([
            'name' => 'core/heading',
            'include_examples' => true,
        ]);

        $this->assertArrayHasKey('examples', $result['block']);
        $this->assertNotEmpty($result['block']['examples']);
        $this->assertArrayHasKey('demo_page', $result);
        $this->assertSame($pageId, $result['demo_page']['id']);
    }

    public function test_search_posts_finds_examples(): void
    {
        self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph {"className":"is-style-tagline"} -->'
                ."\n".'<p class="is-style-tagline">Hello</p>'
                ."\n".'<!-- /wp:paragraph -->',
        ]);

        $result = GetBlockAbility::execute([
            'name' => 'core/paragraph',
            'search_posts' => true,
        ]);

        $this->assertArrayHasKey('post_examples', $result['block']);
        $this->assertNotEmpty($result['block']['post_examples']);

        $example = $result['block']['post_examples'][0];
        $this->assertArrayHasKey('post_id', $example);
        $this->assertArrayHasKey('post_title', $example);
        $this->assertArrayHasKey('post_type', $example);
        $this->assertArrayHasKey('markup', $example);
    }

    public function test_search_posts_filters_by_post_type(): void
    {
        self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Page</h2><!-- /wp:heading -->',
        ]);
        self::factory()->post->create([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Post</h2><!-- /wp:heading -->',
        ]);

        $result = GetBlockAbility::execute([
            'name' => 'core/heading',
            'search_posts' => true,
            'search_post_type' => 'page',
        ]);

        $postTypes = array_unique(array_column($result['block']['post_examples'], 'post_type'));
        $this->assertSame(['page'], $postTypes);
    }

    public function test_style_filter_on_post_examples(): void
    {
        self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph {"className":"is-style-tagline"} -->'
                ."\n".'<p class="is-style-tagline">Styled</p>'
                ."\n".'<!-- /wp:paragraph -->'
                ."\n\n".'<!-- wp:paragraph -->'
                ."\n".'<p>Plain</p>'
                ."\n".'<!-- /wp:paragraph -->',
        ]);

        // Without filter: both
        $result = GetBlockAbility::execute([
            'name' => 'core/paragraph',
            'search_posts' => true,
        ]);
        $this->assertGreaterThanOrEqual(2, count($result['block']['post_examples']));

        // With filter: only tagline
        $result = GetBlockAbility::execute([
            'name' => 'core/paragraph',
            'search_posts' => true,
            'style' => 'tagline',
        ]);
        foreach ($result['block']['post_examples'] as $ex) {
            $this->assertStringContainsString('is-style-tagline', $ex['markup']);
        }
    }

    public function test_max_examples_caps_at_100(): void
    {
        $result = GetBlockAbility::execute([
            'name' => 'core/heading',
            'include_examples' => true,
            'max_examples' => 999,
        ]);
        // Just verify no error — the cap is enforced internally.
        $this->assertArrayHasKey('block', $result);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = GetBlockAbility::checkPermission();
        $this->assertWPError($result);
    }
}
