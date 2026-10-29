<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Changing one block's settings without resending the post.
 */
class BlockEditGuidanceTest extends AbilityTestCase
{
    private const TABLE = "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>Cell</td></tr></tbody></table></figure>\n<!-- /wp:table -->";

    private const BUTTON = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button {\"className\":\"is-style-outline\"} -->\n<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link wp-element-button\" href=\"https://example.com/old\">Go</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_class_name_attr_reaches_the_saved_html(): void
    {
        $id = $this->createPost(['post_content' => self::TABLE]);

        $this->assertAbilitySuccess('gds/blocks-patch', [
            'id' => $id,
            'block_name' => 'core/table',
            'attrs' => ['className' => 'is-style-stripes'],
        ]);

        $content = get_post_field('post_content', $id);
        $this->assertStringContainsString('<!-- wp:table {"className":"is-style-stripes"} -->', $content);
        $this->assertStringContainsString('<figure class="wp-block-table is-style-stripes">', $content);
    }

    public function test_removing_class_name_removes_it_from_the_html(): void
    {
        $id = $this->createPost(['post_content' => self::BUTTON]);

        $this->assertAbilitySuccess('gds/blocks-patch', [
            'id' => $id,
            'block_name' => 'core/button',
            'remove_attrs' => ['className'],
        ]);

        $content = get_post_field('post_content', $id);
        $this->assertStringNotContainsString('is-style-outline', $content);
        $this->assertStringContainsString('<div class="wp-block-button">', $content);
    }

    public function test_replace_points_to_block_attributes_when_text_is_only_there(): void
    {
        $id = $this->createPost(['post_content' => '<!-- wp:paragraph {"className":"jopa-note"} -->'."\n".'<p class="jopa-note">Text</p>'."\n".'<!-- /wp:paragraph -->']);

        $result = $this->assertAbilitySuccess('gds/content-replace', ['id' => $id, 'search' => 'jopa-note', 'replace' => 'x', 'dry_run' => true]);

        $this->assertSame(0, $result['replaced_count']);
        $this->assertStringContainsString('1 in block attributes', $result['hint']);
    }

    public function test_replace_points_to_blocks_patch_when_text_is_only_in_markup(): void
    {
        $id = $this->createPost(['post_content' => self::BUTTON]);

        $result = $this->assertAbilitySuccess('gds/content-replace', ['id' => $id, 'search' => 'example.com/old', 'replace' => 'x']);

        $this->assertSame(0, $result['replaced_count']);
        $this->assertStringContainsString('gds/blocks-patch', $result['hint']);
    }

    public function test_replace_gives_no_hint_when_text_is_nowhere(): void
    {
        $id = $this->createPost(['post_content' => self::TABLE]);

        $result = $this->assertAbilitySuccess('gds/content-replace', ['id' => $id, 'search' => 'missing', 'replace' => 'x']);

        $this->assertArrayNotHasKey('hint', $result);
    }
}
