<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Abilities\PatchBlockAbility;
use GeneroWP\MCP\Tests\AbilityTestCase;
use WP_Theme_JSON_Data;
use WP_Theme_JSON_Resolver;

/**
 * Block markup checks shared by content-create, content-update and
 * blocks-patch.
 */
class BlockLintTest extends AbilityTestCase
{
    private const VALID_MARKUP = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->'
        .'<p>Some classic HTML.</p>'
        .'<!-- wp:group {"backgroundColor":"brand","style":{"spacing":{"padding":{"top":"var:preset|spacing|70"}}}} --><div class="wp-block-group has-brand-background-color has-background">'
        .'<!-- wp:paragraph {"className":"intro","fontSize":"huge-x"} --><p class="intro has-huge-x-font-size">Body</p><!-- /wp:paragraph -->'
        .'<!-- wp:test/lint-card {"title":"Card","size":"small","textColor":"brand","style":{"color":{"background":"var:preset|color|brand"}}} /-->'
        .'</div><!-- /wp:group -->';

    protected function setUp(): void
    {
        parent::setUp();

        register_block_type('test/lint-card', [
            'attributes' => [
                'title' => ['type' => 'string'],
                'size' => ['type' => 'string', 'enum' => ['small', 'large']],
            ],
            'supports' => ['color' => ['text' => true, 'background' => true]],
        ]);
        register_block_type('test/lint-link', [
            'attributes' => [
                'url' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'a[data-link]', 'attribute' => 'href'],
                'image' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'img', 'attribute' => 'src'],
            ],
        ]);

        add_filter('wp_theme_json_data_theme', [$this, 'addPresets']);
        $this->cleanThemeJson();
    }

    protected function tearDown(): void
    {
        unregister_block_type('test/lint-card');
        unregister_block_type('test/lint-link');
        remove_filter('wp_theme_json_data_theme', [$this, 'addPresets']);
        $this->cleanThemeJson();

        parent::tearDown();
    }

    public function addPresets(WP_Theme_JSON_Data $data): WP_Theme_JSON_Data
    {
        return $data->update_with([
            'version' => 3,
            'settings' => [
                'color' => ['palette' => [['slug' => 'brand', 'color' => '#123456', 'name' => 'Brand']]],
                'typography' => ['fontSizes' => [['slug' => 'huge-x', 'size' => '3.25rem', 'name' => 'Huge']]],
                'spacing' => ['spacingSizes' => [['slug' => '70', 'size' => '4.75rem', 'name' => 'Large']]],
            ],
        ]);
    }

    private function cleanThemeJson(): void
    {
        wp_clean_theme_json_cache();
        WP_Theme_JSON_Resolver::clean_cached_data();
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function create(string $content): mixed
    {
        return $this->executeAbility('gds/content-create', [
            'type' => 'posts',
            'title' => 'Lint',
            'content' => $content,
        ]);
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>|\WP_Error
     */
    private function patch(string $content, array $op): mixed
    {
        $id = $this->createPost(['post_content' => $content]);

        return (new PatchBlockAbility)->execute(['id' => $id] + $op);
    }

    // ── Valid markup ────────────────────────────────────────────

    public function test_valid_core_and_custom_markup_passes_without_warnings(): void
    {
        $result = $this->assertAbilitySuccess('gds/content-create', [
            'type' => 'posts',
            'title' => 'Valid',
            'content' => self::VALID_MARKUP,
        ]);

        $this->assertArrayNotHasKey('warnings', $result);
        $this->assertStringContainsString('wp:test/lint-card', get_post_field('post_content', $result['id']));
    }

    public function test_valid_insert_passes_without_warnings(): void
    {
        $result = $this->patch('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', [
            'block_name' => 'core/paragraph',
            'action' => 'insert',
            'markup' => self::VALID_MARKUP,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('warnings', $result);
    }

    // ── Unknown block names ─────────────────────────────────────

    public function test_unknown_block_name_is_rejected_with_the_closest_name(): void
    {
        $error = $this->create('<!-- wp:paragrah --><p>x</p><!-- /wp:paragrah -->');

        $this->assertWPError($error);
        $this->assertSame('unknown_block_type', $error->get_error_code());
        $this->assertStringContainsString('"core/paragrah"', $error->get_error_message());
        $this->assertStringContainsString('"core/paragraph"', $error->get_error_message());
    }

    public function test_unknown_block_name_in_an_insert_saves_nothing(): void
    {
        $content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
        $id = $this->createPost(['post_content' => $content]);

        $result = (new PatchBlockAbility)->execute([
            'id' => $id,
            'block_name' => 'core/paragraph',
            'action' => 'insert',
            'markup' => '<!-- wp:test/lint-crad {"title":"x"} /-->',
        ]);

        $this->assertWPError($result);
        $this->assertStringContainsString('"test/lint-card"', $result->get_error_data()['results'][0]['error']);
        $this->assertSame($content, get_post_field('post_content', $id));
    }

    public function test_unknown_block_name_in_inner_blocks_is_rejected(): void
    {
        $result = $this->patch('<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->', [
            'block_name' => 'core/group',
            'inner_blocks' => '<!-- wp:gds/no-such-block /-->',
        ]);

        $this->assertWPError($result);
        $this->assertStringContainsString('Unknown block type "gds/no-such-block"', $result->get_error_data()['results'][0]['error']);
    }

    public function test_block_already_in_saved_content_is_accepted_though_unregistered(): void
    {
        // Blocks registered only in JavaScript have no PHP registration.
        $this->createPost(['post_content' => '<!-- wp:acme/editor-only {"x":1} /-->']);

        $result = $this->create('<!-- wp:acme/editor-only {"x":2} /-->');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('warnings', $result);
    }

    public function test_core_missing_is_rejected(): void
    {
        $error = $this->create('<!-- wp:missing {"originalName":"acme/x"} /-->');

        $this->assertWPError($error);
        $this->assertStringContainsString('core/missing', $error->get_error_message());
    }

    // ── Attributes ──────────────────────────────────────────────

    public function test_typoed_attribute_is_warned_on_create(): void
    {
        $result = $this->assertAbilitySuccess('gds/content-create', [
            'type' => 'posts',
            'title' => 'Typo',
            'content' => '<!-- wp:heading {"levle":3} --><h2 class="wp-block-heading">T</h2><!-- /wp:heading -->',
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('core/heading has no attribute "levle" (closest: "level")', $result['warnings'][0]);
    }

    public function test_typoed_attribute_is_warned_on_update(): void
    {
        $id = $this->createPost();

        $result = $this->assertAbilitySuccess('gds/content-update', [
            'type' => 'posts',
            'id' => $id,
            'content' => '<!-- wp:test/lint-card {"titel":"x"} /-->',
        ]);

        $this->assertStringContainsString('test/lint-card has no attribute "titel" (closest: "title")', $result['warnings'][0]);
    }

    public function test_typoed_attribute_is_warned_on_patch(): void
    {
        $result = $this->patch('<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">T</h2><!-- /wp:heading -->', [
            'block_name' => 'core/heading',
            'attrs' => ['levle' => 3],
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(['operations[0]: core/heading has no attribute "levle" (closest: "level"); the editor drops it.'], $result['warnings']);
    }

    public function test_invalid_attribute_values_are_warned(): void
    {
        $result = $this->create('<!-- wp:heading {"level":"big"} --><h2 class="wp-block-heading">T</h2><!-- /wp:heading -->'
            .'<!-- wp:test/lint-card {"size":"medium"} /-->');

        $this->assertCount(2, $result['warnings']);
        $this->assertStringContainsString('core/heading attribute "level"', $result['warnings'][0]);
        $this->assertStringContainsString('test/lint-card attribute "size"', $result['warnings'][1]);
    }

    // ── Synced patterns ─────────────────────────────────────────

    public function test_synced_pattern_with_inner_blocks_is_refused(): void
    {
        $error = $this->create('<!-- wp:block {"ref":12} --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:block -->');

        $this->assertWPError($error);
        $this->assertSame('synced_pattern_content', $error->get_error_code());
        $this->assertStringContainsString('id 12', $error->get_error_message());
    }

    public function test_synced_pattern_with_inner_html_is_refused_in_an_insert(): void
    {
        $result = $this->patch('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', [
            'block_name' => 'core/paragraph',
            'action' => 'insert',
            'markup' => '<!-- wp:block {"ref":12} --><p>copied pattern content</p><!-- /wp:block -->',
        ]);

        $this->assertWPError($result);
        $this->assertStringContainsString('synced pattern', $result->get_error_data()['results'][0]['error']);
    }

    public function test_synced_pattern_reference_is_accepted(): void
    {
        $result = $this->create('<!-- wp:block {"ref":12} /-->');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('warnings', $result);
    }

    public function test_inner_blocks_patch_on_a_synced_pattern_is_refused(): void
    {
        $result = $this->patch('<!-- wp:block {"ref":34} /-->', [
            'block_name' => 'core/block',
            'inner_blocks' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        ]);

        $this->assertWPError($result);
        $error = $result->get_error_data()['results'][0]['error'];
        $this->assertStringContainsString('id 34', $error);
        $this->assertStringContainsString('gds/blocks-patch', $error);
    }

    public function test_append_into_a_synced_pattern_is_refused(): void
    {
        $result = $this->patch('<!-- wp:block {"ref":34} /-->', [
            'block_name' => 'core/block',
            'action' => 'insert',
            'position' => 'append',
            'markup' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        ]);

        $this->assertWPError($result);
        $this->assertStringContainsString('synced pattern', $result->get_error_data()['results'][0]['error']);
    }

    // ── Attribute → HTML sync ───────────────────────────────────

    public function test_attribute_with_an_unsupported_selector_is_warned(): void
    {
        $result = $this->patch('<!-- wp:test/lint-link --><div><a data-link href="https://old.example">x</a></div><!-- /wp:test/lint-link -->', [
            'block_name' => 'test/lint-link',
            'attrs' => ['url' => 'https://new.example'],
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('test/lint-link attribute "url"', $result['warnings'][0]);
        $this->assertStringContainsString('too complex', $result['warnings'][0]);
        $this->assertStringContainsString('inner_html', $result['warnings'][0]);
    }

    public function test_attribute_whose_selector_matches_nothing_is_warned(): void
    {
        $result = $this->patch('<!-- wp:test/lint-link --><div><a data-link href="https://old.example">x</a></div><!-- /wp:test/lint-link -->', [
            'block_name' => 'test/lint-link',
            'attrs' => ['image' => 'https://example.com/a.jpg'],
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('attribute "image"', $result['warnings'][0]);
        $this->assertStringContainsString('no element', $result['warnings'][0]);
    }

    public function test_synced_attribute_is_not_warned(): void
    {
        $result = $this->patch('<!-- wp:test/lint-link --><div><img src="https://example.com/old.jpg"/></div><!-- /wp:test/lint-link -->', [
            'block_name' => 'test/lint-link',
            'attrs' => ['image' => 'https://example.com/new.jpg'],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('warnings', $result);
        $this->assertStringContainsString('new.jpg', get_post_field('post_content', $result['id']));
    }

    // ── Theme presets ───────────────────────────────────────────

    public function test_raw_color_equal_to_a_palette_color_is_warned(): void
    {
        $result = $this->create('<!-- wp:group {"style":{"color":{"background":"#123456"}}} --><div class="wp-block-group has-background" style="background-color:#123456"></div><!-- /wp:group -->');

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('style.color.background "#123456" is the "brand" color preset; set backgroundColor: "brand"', $result['warnings'][0]);
        // Warned, never rewritten.
        $this->assertStringContainsString('"background":"#123456"', get_post_field('post_content', $result['id']));
    }

    public function test_raw_font_size_equal_to_a_preset_is_warned(): void
    {
        $result = $this->patch('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', [
            'block_name' => 'core/paragraph',
            'attrs' => ['style' => ['typography' => ['fontSize' => '3.25rem']]],
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('is the "huge-x" font-size preset; set fontSize: "huge-x"', $result['warnings'][0]);
    }

    public function test_raw_spacing_equal_to_a_preset_is_warned(): void
    {
        $result = $this->create('<!-- wp:group {"style":{"spacing":{"padding":{"top":"4.75rem","bottom":"1px"}}}} --><div class="wp-block-group" style="padding-top:4.75rem;padding-bottom:1px"></div><!-- /wp:group -->');

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('style.spacing.padding.top "4.75rem" is the "70" spacing preset; use "var:preset|spacing|70"', $result['warnings'][0]);
    }
}
