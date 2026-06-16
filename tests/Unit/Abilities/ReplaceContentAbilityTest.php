<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ReplaceContentAbility;
use WP_UnitTestCase;

class ReplaceContentAbilityTest extends WP_UnitTestCase
{
    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->postId = self::factory()->post->create([
            'post_title' => 'Surface treatment',
            'post_content' => implode("\n\n", [
                '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Pintakäsittelyä ilman epävarmuutta</h2><!-- /wp:heading -->',
                '<!-- wp:paragraph --><p>Etenevät ilman yllätyksiä.</p><!-- /wp:paragraph -->',
                '<!-- wp:paragraph --><p>Tuotantosi jatkuu ilman katkoksia.</p><!-- /wp:paragraph -->',
                '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Pelkkä esikäsittely ilman maalausta.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
            ]),
        ]);
    }

    public function test_replaces_every_occurrence_across_nested_blocks(): void
    {
        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['replaced_count']);
        $this->assertFalse($result['dry_run']);

        $content = get_post($this->postId)->post_content;
        $this->assertSame(4, substr_count($content, 'Vilman'));
        $this->assertStringContainsString('Pintakäsittelyä Vilman epävarmuutta', $content);
        $this->assertStringContainsString('jatkuu Vilman katkoksia', $content);
        // The nested (group) paragraph was reached too.
        $this->assertStringContainsString('esikäsittely Vilman maalausta', $content);
    }

    public function test_preserves_backslashes_in_content(): void
    {
        // wp_update_post() unslashes its input; the ability must wp_slash() so
        // backslashes (code blocks, paths) survive the write.
        wp_update_post([
            'ID' => $this->postId,
            'post_content' => wp_slash('<!-- wp:paragraph --><p>Path C:\Users\test needs ilman.</p><!-- /wp:paragraph -->'),
        ]);

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
        ]);

        $this->assertSame(1, $result['replaced_count']);

        $content = get_post($this->postId)->post_content;
        $this->assertStringContainsString('C:\Users\test', $content);
        $this->assertStringContainsString('Vilman', $content);
    }

    public function test_whole_word_does_not_match_inside_longer_words(): void
    {
        wp_update_post([
            'ID' => $this->postId,
            'post_content' => '<!-- wp:paragraph --><p>Tarvitsemme ilman ja ilmanvaihto on tärkeä.</p><!-- /wp:paragraph -->',
        ]);

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'happi',
            'whole_word' => true,
        ]);

        $this->assertSame(1, $result['replaced_count']);

        $content = get_post($this->postId)->post_content;
        $this->assertStringContainsString('happi ja ilmanvaihto', $content);
    }

    public function test_does_not_touch_urls_or_classes(): void
    {
        wp_update_post([
            'ID' => $this->postId,
            'post_content' => '<!-- wp:paragraph --><p>Lue <a href="https://ilman.example" class="ilman-link">ilman</a> lisää.</p><!-- /wp:paragraph -->',
        ]);

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
        ]);

        // Only the visible anchor text is changed, not href or class.
        $this->assertSame(1, $result['replaced_count']);

        $content = get_post($this->postId)->post_content;
        $this->assertStringContainsString('href="https://ilman.example"', $content);
        $this->assertStringContainsString('class="ilman-link"', $content);
        $this->assertStringContainsString('>Vilman</a>', $content);
    }

    public function test_case_sensitive_by_default(): void
    {
        wp_update_post([
            'ID' => $this->postId,
            'post_content' => '<!-- wp:paragraph --><p>Ilman ja ilman.</p><!-- /wp:paragraph -->',
        ]);

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'X',
        ]);

        $this->assertSame(1, $result['replaced_count']);
        $this->assertStringContainsString('Ilman ja X.', get_post($this->postId)->post_content);
    }

    public function test_case_insensitive_when_disabled(): void
    {
        wp_update_post([
            'ID' => $this->postId,
            'post_content' => '<!-- wp:paragraph --><p>Ilman ja ilman.</p><!-- /wp:paragraph -->',
        ]);

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'X',
            'case_sensitive' => false,
        ]);

        $this->assertSame(2, $result['replaced_count']);
        $this->assertStringContainsString('X ja X.', get_post($this->postId)->post_content);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $before = get_post($this->postId)->post_content;

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
            'dry_run' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(4, $result['replaced_count']);
        $this->assertNotEmpty($result['samples']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertArrayNotHasKey('_undo', $result);

        // Nothing written.
        $this->assertSame($before, get_post($this->postId)->post_content);
    }

    public function test_expect_count_mismatch_aborts_without_writing(): void
    {
        $before = get_post($this->postId)->post_content;

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
            'expect_count' => 1,
        ]);

        $this->assertWPError($result);
        $this->assertSame('unexpected_count', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame(1, $data['expected']);
        $this->assertSame(4, $data['actual']);

        $this->assertSame($before, get_post($this->postId)->post_content);
    }

    public function test_expect_count_match_proceeds(): void
    {
        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
            'expect_count' => 4,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['replaced_count']);
    }

    public function test_include_attrs_replaces_text_stored_in_attributes(): void
    {
        wp_update_post([
            'ID' => $this->postId,
            'post_content' => '<!-- wp:gds/check-list {"items":[{"text":"buy ilman"}]} /-->',
        ]);

        // Without include_attrs, attribute text is left untouched.
        $without = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
        ]);
        $this->assertSame(0, $without['replaced_count']);

        // With include_attrs, the attribute value is rewritten.
        $with = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
            'include_attrs' => true,
        ]);

        $this->assertSame(1, $with['replaced_count']);
        $this->assertStringContainsString('buy Vilman', get_post($this->postId)->post_content);
    }

    public function test_no_matches_returns_zero_and_does_not_write(): void
    {
        $before = get_post($this->postId)->post_content;

        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'nonexistent-phrase',
            'replace' => 'whatever',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['replaced_count']);
        $this->assertArrayNotHasKey('_undo', $result);
        $this->assertSame($before, get_post($this->postId)->post_content);
    }

    public function test_result_is_reversible(): void
    {
        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => 'ilman',
            'replace' => 'Vilman',
        ]);

        $this->assertArrayHasKey('_undo', $result);
        $this->assertSame('restore-post', $result['_undo']['kind']);
    }

    public function test_multi_post_replaces_across_posts(): void
    {
        $a = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>Contact old@x.fi today.</p><!-- /wp:paragraph -->',
        ]);
        $b = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>old@x.fi and old@x.fi.</p><!-- /wp:paragraph -->',
        ]);

        $result = (new ReplaceContentAbility)->execute([
            'ids' => [$a, $b],
            'search' => 'old@x.fi',
            'replace' => 'new@x.fi',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['replaced_count']);
        $this->assertSame(2, $result['updated_posts']);
        $this->assertCount(2, $result['posts']);

        $this->assertStringContainsString('new@x.fi', get_post($a)->post_content);
        $this->assertSame(2, substr_count(get_post($b)->post_content, 'new@x.fi'));

        // Multi-post writes are reverted together via a single bulk undo.
        $this->assertSame('bulk', $result['_undo']['kind']);
        $this->assertCount(2, $result['_undo']['data']['items']);
    }

    public function test_multi_post_dry_run_aggregates_without_writing(): void
    {
        $a = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>old@x.fi</p><!-- /wp:paragraph -->',
        ]);
        $b = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>old@x.fi old@x.fi</p><!-- /wp:paragraph -->',
        ]);
        $beforeA = get_post($a)->post_content;
        $beforeB = get_post($b)->post_content;

        $result = (new ReplaceContentAbility)->execute([
            'ids' => [$a, $b],
            'search' => 'old@x.fi',
            'replace' => 'new@x.fi',
            'dry_run' => true,
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(3, $result['replaced_count']);
        $this->assertArrayNotHasKey('_undo', $result);
        $this->assertSame($beforeA, get_post($a)->post_content);
        $this->assertSame($beforeB, get_post($b)->post_content);
    }

    public function test_multi_post_expect_count_mismatch_aborts_all(): void
    {
        $a = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>old@x.fi</p><!-- /wp:paragraph -->',
        ]);
        $b = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>old@x.fi old@x.fi</p><!-- /wp:paragraph -->',
        ]);
        $beforeA = get_post($a)->post_content;
        $beforeB = get_post($b)->post_content;

        $result = (new ReplaceContentAbility)->execute([
            'ids' => [$a, $b],
            'search' => 'old@x.fi',
            'replace' => 'new@x.fi',
            'expect_count' => 2, // actual is 3
        ]);

        $this->assertWPError($result);
        $this->assertSame('unexpected_count', $result->get_error_code());
        $this->assertSame(3, $result->get_error_data()['actual']);

        // No post was touched.
        $this->assertSame($beforeA, get_post($a)->post_content);
        $this->assertSame($beforeB, get_post($b)->post_content);
    }

    public function test_missing_search_errors(): void
    {
        $result = (new ReplaceContentAbility)->execute([
            'id' => $this->postId,
            'search' => '',
            'replace' => 'x',
        ]);

        $this->assertWPError($result);
        $this->assertSame('missing_search', $result->get_error_code());
    }
}
