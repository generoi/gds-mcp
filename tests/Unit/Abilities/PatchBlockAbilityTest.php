<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\PatchBlockAbility;
use WP_UnitTestCase;

class PatchBlockAbilityTest extends WP_UnitTestCase
{
    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->postId = self::factory()->post->create([
            'post_content' => implode("\n\n", [
                '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->',
                '<!-- wp:paragraph --><p>First paragraph</p><!-- /wp:paragraph -->',
                '<!-- wp:paragraph {"className":"special"} --><p>Second paragraph</p><!-- /wp:paragraph -->',
                '<!-- wp:group {"backgroundColor":"beige"} --><div class="wp-block-group"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Nested</h3><!-- /wp:heading --></div><!-- /wp:group -->',
            ]),
        ]);
    }

    public function test_merge_attrs(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 2,
            'attrs' => ['align' => 'center'],
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_modified']);

        // Verify the second paragraph got the new attr merged with existing className.
        $blocks = parse_blocks(get_post($this->postId)->post_content);
        $paragraphs = array_values(array_filter($blocks, fn ($b) => $b['blockName'] === 'core/paragraph'));
        $this->assertSame('center', $paragraphs[1]['attrs']['align']);
        $this->assertSame('special', $paragraphs[1]['attrs']['className']);
    }

    public function test_set_attrs_replaces_all(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 2,
            'set_attrs' => ['align' => 'right'],
        ]);

        $this->assertTrue($result['success']);

        $blocks = parse_blocks(get_post($this->postId)->post_content);
        $paragraphs = array_values(array_filter($blocks, fn ($b) => $b['blockName'] === 'core/paragraph'));
        $this->assertSame(['align' => 'right'], $paragraphs[1]['attrs']);
        $this->assertArrayNotHasKey('className', $paragraphs[1]['attrs']);
    }

    public function test_remove_attrs(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 2,
            'remove_attrs' => ['className'],
        ]);

        $this->assertTrue($result['success']);

        $blocks = parse_blocks(get_post($this->postId)->post_content);
        $paragraphs = array_values(array_filter($blocks, fn ($b) => $b['blockName'] === 'core/paragraph'));
        $this->assertArrayNotHasKey('className', $paragraphs[1]['attrs']);
    }

    public function test_inner_html(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 1,
            'inner_html' => '<p>Updated text</p>',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Updated text', get_post($this->postId)->post_content);
    }

    public function test_inner_html_errors_on_block_with_children(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/group',
            'inner_html' => '<div>nope</div>',
        ]);

        // Single failing operation returns WP_Error with all_operations_failed.
        $this->assertWPError($result);
        $this->assertSame('all_operations_failed', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertStringContainsString('inner blocks', $data['results'][0]['error']);
    }

    public function test_inner_blocks(): void
    {
        $newBlocks = '<!-- wp:paragraph --><p>Replaced child</p><!-- /wp:paragraph -->';

        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/group',
            'inner_blocks' => $newBlocks,
        ]);

        $this->assertTrue($result['success']);

        $blocks = parse_blocks(get_post($this->postId)->post_content);
        $group = array_values(array_filter($blocks, fn ($b) => $b['blockName'] === 'core/group'))[0];
        $this->assertCount(1, $group['innerBlocks']);
        $this->assertSame('core/paragraph', $group['innerBlocks'][0]['blockName']);
        $this->assertStringContainsString('<div class="wp-block-group"><!-- wp:paragraph --><p>Replaced child</p><!-- /wp:paragraph --></div>', get_post($this->postId)->post_content);
    }

    public function test_delete_occurrence_one_removes_the_first_match(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'action' => 'delete',
            'block_name' => 'core/paragraph',
            'occurrence' => 1,
        ]);

        $this->assertTrue($result['success']);
        $content = get_post($this->postId)->post_content;
        $this->assertStringNotContainsString('First paragraph', $content);
        $this->assertStringContainsString('Second paragraph', $content);
    }

    public function test_delete_leaves_other_blocks_markup_byte_identical(): void
    {
        $group = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>In group</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
        $columns = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading --><h2 class="wp-block-heading">Col</h2><!-- /wp:heading --></div><!-- /wp:column --></div><!-- /wp:columns -->';
        $postId = self::factory()->post->create([
            'post_content' => implode("\n\n", ['<!-- wp:paragraph --><p>Top</p><!-- /wp:paragraph -->', $group, $columns]),
        ]);

        $result = (new PatchBlockAbility)->execute(['id' => $postId, 'action' => 'delete', 'block_name' => 'core/paragraph']);

        $this->assertTrue($result['success']);
        $content = get_post($postId)->post_content;
        $this->assertStringNotContainsString('Top', $content);
        $this->assertStringContainsString($group, $content);
        $this->assertStringContainsString($columns, $content);

        // A nested delete keeps the parent's wrapper.
        $result = (new PatchBlockAbility)->execute(['id' => $postId, 'action' => 'delete', 'block_name' => 'core/heading']);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('<!-- wp:column --><div class="wp-block-column"></div><!-- /wp:column -->', get_post($postId)->post_content);
    }

    public function test_insert_into_a_list_keeps_the_list_markup(): void
    {
        $postId = self::factory()->post->create([
            'post_content' => '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
        ]);

        $result = (new PatchBlockAbility)->execute([
            'id' => $postId,
            'operations' => [
                ['action' => 'insert', 'block_name' => 'core/list-item', 'position' => 'after', 'markup' => '<!-- wp:list-item --><li>Two</li><!-- /wp:list-item -->'],
                ['action' => 'insert', 'block_name' => 'core/list', 'position' => 'append', 'markup' => '<!-- wp:list-item --><li>Three</li><!-- /wp:list-item -->'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two</li><!-- /wp:list-item --><!-- wp:list-item --><li>Three</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
            get_post($postId)->post_content,
        );
    }

    public function test_insert_into_a_group_keeps_its_wrapper(): void
    {
        $postId = self::factory()->post->create([
            'post_content' => implode("\n\n", [
                '<!-- wp:group {"backgroundColor":"beige"} --><div class="wp-block-group has-beige-background-color"><!-- wp:paragraph --><p>Existing</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
                '<!-- wp:group {"className":"empty"} --><div class="wp-block-group empty"></div><!-- /wp:group -->',
            ]),
        ]);

        $result = (new PatchBlockAbility)->execute([
            'id' => $postId,
            'operations' => [
                ['action' => 'insert', 'block_name' => 'core/group', 'occurrence' => 1, 'position' => 'prepend', 'markup' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->'],
                ['action' => 'insert', 'block_name' => 'core/group', 'occurrence' => 2, 'position' => 'append', 'markup' => '<!-- wp:paragraph --><p>Filled</p><!-- /wp:paragraph -->'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $content = get_post($postId)->post_content;
        $this->assertStringContainsString('<div class="wp-block-group has-beige-background-color"><!-- wp:paragraph --><p>New</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Existing</p><!-- /wp:paragraph --></div>', $content);
        $this->assertStringContainsString('<div class="wp-block-group empty"><!-- wp:paragraph --><p>Filled</p><!-- /wp:paragraph --></div>', $content);
    }

    public function test_occurrence_zero_patches_all(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 0,
            'attrs' => ['textAlign' => 'center'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total_modified']);

        $blocks = parse_blocks(get_post($this->postId)->post_content);
        $paragraphs = array_values(array_filter($blocks, fn ($b) => $b['blockName'] === 'core/paragraph'));
        foreach ($paragraphs as $p) {
            $this->assertSame('center', $p['attrs']['textAlign']);
        }
    }

    public function test_occurrence_zero_with_inner_html_is_rejected(): void
    {
        // Regression: occurrence:0 + inner_html would broadcast identical text
        // into every matching block (the "all paragraphs overwritten" footgun).
        $before = get_post($this->postId)->post_content;

        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 0,
            'inner_html' => '<p>Broadcast</p>',
        ]);

        $this->assertWPError($result);
        $this->assertSame('occurrence_zero_content', $result->get_error_code());

        // Nothing was written.
        $this->assertSame($before, get_post($this->postId)->post_content);
    }

    public function test_occurrence_zero_with_inner_blocks_is_rejected(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/group',
            'occurrence' => 0,
            'inner_blocks' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        ]);

        $this->assertWPError($result);
        $this->assertSame('occurrence_zero_content', $result->get_error_code());
    }

    public function test_nested_block_search(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/heading',
            'occurrence' => 2,
            'attrs' => ['textAlign' => 'right'],
        ]);

        $this->assertTrue($result['success']);

        // The second heading is nested inside the group.
        $blocks = parse_blocks(get_post($this->postId)->post_content);
        $group = array_values(array_filter($blocks, fn ($b) => $b['blockName'] === 'core/group'))[0];
        $this->assertSame('right', $group['innerBlocks'][0]['attrs']['textAlign']);
    }

    public function test_batch_operations(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'operations' => [
                [
                    'block_name' => 'core/heading',
                    'occurrence' => 1,
                    'attrs' => ['textAlign' => 'center'],
                ],
                [
                    'block_name' => 'core/paragraph',
                    'occurrence' => 1,
                    'inner_html' => '<p>Batch updated</p>',
                ],
                [
                    'block_name' => 'core/group',
                    'attrs' => ['className' => 'new-class'],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['results']);
        $this->assertSame(3, $result['total_modified']);

        $content = get_post($this->postId)->post_content;
        $this->assertStringContainsString('Batch updated', $content);
    }

    public function test_batch_with_a_failing_operation_saves_nothing(): void
    {
        $before = get_post($this->postId)->post_content;

        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'operations' => [
                ['block_name' => 'core/heading', 'occurrence' => 1, 'attrs' => ['textAlign' => 'center']],
                ['block_name' => 'core/nonexistent', 'attrs' => ['foo' => 'bar']],
            ],
        ]);

        $this->assertWPError($result);
        $this->assertSame('operation_failed', $result->get_error_code());
        $this->assertSame($before, get_post($this->postId)->post_content);
    }

    public function test_block_not_found(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/nonexistent',
            'attrs' => ['foo' => 'bar'],
        ]);

        $this->assertWPError($result);
        $this->assertSame('all_operations_failed', $result->get_error_code());
    }

    public function test_no_operations_error(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/heading',
        ]);

        $this->assertWPError($result);
        $this->assertSame('no_patch', $result->get_error_code());
    }

    public function test_missing_post_error(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'post_id' => 999999,
            'block_name' => 'core/heading',
            'attrs' => ['level' => 3],
        ]);

        $this->assertWPError($result);
        $this->assertSame('missing_id', $result->get_error_code());
    }

    public function test_round_trip_preserves_content(): void
    {
        $originalContent = get_post($this->postId)->post_content;

        // Patch and unpatch: set an attr then remove it.
        (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/heading',
            'occurrence' => 1,
            'attrs' => ['tempAttr' => 'temp'],
        ]);

        (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/heading',
            'occurrence' => 1,
            'remove_attrs' => ['tempAttr'],
        ]);

        // Content should be semantically equivalent after round-trip.
        $finalBlocks = parse_blocks(get_post($this->postId)->post_content);
        $originalBlocks = parse_blocks($originalContent);

        // Compare block structure (attrs and block names).
        $this->assertSame(
            $this->extractBlockNames($originalBlocks),
            $this->extractBlockNames($finalBlocks)
        );
    }

    private function extractBlockNames(array $blocks): array
    {
        $names = [];
        foreach ($blocks as $block) {
            if ($block['blockName'] !== null) {
                $names[] = $block['blockName'];
                if (! empty($block['innerBlocks'])) {
                    $names = array_merge($names, $this->extractBlockNames($block['innerBlocks']));
                }
            }
        }

        return $names;
    }

    // ── Composite template id resolution ─────────────────────────────────

    /**
     * Create a wp_template_part post owned by the given theme term.
     *
     * Switches the current user to administrator — wp_template_part's
     * meta-cap mapping requires edit_theme_options, which 'editor' lacks.
     */
    private function createTemplatePart(string $theme, string $slug, string $content): int
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (! taxonomy_exists('wp_theme')) {
            register_taxonomy('wp_theme', ['wp_template', 'wp_template_part'], [
                'public' => false,
                'hierarchical' => false,
                'rewrite' => false,
                'show_ui' => false,
            ]);
        }

        $existing = term_exists($theme, 'wp_theme');
        if ($existing) {
            $termId = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
        } else {
            $term = wp_insert_term($theme, 'wp_theme');
            $termId = is_wp_error($term) ? (int) $term->get_error_data() : (int) $term['term_id'];
        }

        $postId = self::factory()->post->create([
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_content' => $content,
        ]);
        wp_set_object_terms($postId, [$termId], 'wp_theme');

        return $postId;
    }

    public function test_resolves_composite_template_id(): void
    {
        $partId = $this->createTemplatePart(
            'gds',
            'footer',
            '<!-- wp:paragraph --><p>Footer text</p><!-- /wp:paragraph -->',
        );

        $result = (new PatchBlockAbility)->execute([
            'id' => 'gds//footer',
            'block_name' => 'core/paragraph',
            'attrs' => ['align' => 'center'],
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame($partId, $result['id']);
        $this->assertSame(1, $result['total_modified']);

        $blocks = parse_blocks(get_post($partId)->post_content);
        $this->assertSame('center', $blocks[0]['attrs']['align']);
    }

    public function test_resolves_polylang_translated_slug_suffix(): void
    {
        // Polylang Pro suffixes translated wp_template_part slugs with "___{lang}".
        // Each suffix variant is its own post; the resolver should pick the right one.
        $fiId = $this->createTemplatePart('gds', 'footer', '<!-- wp:paragraph --><p>FI</p><!-- /wp:paragraph -->');
        $svId = $this->createTemplatePart('gds', 'footer___sv', '<!-- wp:paragraph --><p>SV</p><!-- /wp:paragraph -->');

        $resultSv = (new PatchBlockAbility)->execute([
            'id' => 'gds//footer___sv',
            'block_name' => 'core/paragraph',
            'attrs' => ['align' => 'right'],
        ]);

        $this->assertIsArray($resultSv);
        $this->assertTrue($resultSv['success']);
        $this->assertSame($svId, $resultSv['id']);

        // FI part must be untouched.
        $fiBlocks = parse_blocks(get_post($fiId)->post_content);
        $this->assertArrayNotHasKey('align', $fiBlocks[0]['attrs'] ?? []);

        $svBlocks = parse_blocks(get_post($svId)->post_content);
        $this->assertSame('right', $svBlocks[0]['attrs']['align']);
    }

    public function test_composite_id_unknown_theme_returns_error(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $result = (new PatchBlockAbility)->execute([
            'id' => 'nonexistent-theme//footer',
            'block_name' => 'core/paragraph',
            'attrs' => ['align' => 'center'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('template_not_customized', $result->get_error_code());
    }

    public function test_composite_id_unknown_slug_returns_error(): void
    {
        $this->createTemplatePart('gds', 'header', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->');

        $result = (new PatchBlockAbility)->execute([
            'id' => 'gds//does-not-exist',
            'block_name' => 'core/paragraph',
            'attrs' => ['align' => 'center'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('template_not_customized', $result->get_error_code());
    }

    public function test_malformed_composite_id_returns_error(): void
    {
        $result = (new PatchBlockAbility)->execute([
            'id' => 'just-a-slug//',
            'block_name' => 'core/paragraph',
            'attrs' => ['align' => 'center'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_template_id', $result->get_error_code());
    }

    public function test_numeric_id_still_works(): void
    {
        // Sanity: existing integer-id callers shouldn't regress.
        $result = (new PatchBlockAbility)->execute([
            'id' => $this->postId,
            'block_name' => 'core/paragraph',
            'occurrence' => 1,
            'attrs' => ['align' => 'left'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($this->postId, $result['id']);
    }
}
