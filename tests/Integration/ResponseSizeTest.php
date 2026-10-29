<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;
use WP\MCP\Core\McpAdapter;

/**
 * What remote MCP clients get back: raw markup to edit, and writes that do not
 * echo the whole post.
 */
class ResponseSizeTest extends AbilityTestCase
{
    private const MARKUP = "<!-- wp:paragraph -->\n<p>Hello <strong>world</strong></p>\n<!-- /wp:paragraph -->";

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp']->query_vars['rest_route']);
        parent::tearDown();
    }

    private function simulateMcpRequest(): void
    {
        $servers = class_exists('\WP\MCP\Core\McpAdapter') ? McpAdapter::instance()->get_servers() : [];
        if (! $servers) {
            $this->markTestSkipped('No MCP servers registered.');
        }
        $server = reset($servers);
        $GLOBALS['wp']->query_vars['rest_route'] = '/'.$server->get_server_route_namespace().'/'.$server->get_server_route();
    }

    public function test_read_returns_raw_block_markup_without_rendered_html(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => self::MARKUP]);

        $result = $this->assertAbilitySuccess('gds/content-read', ['type' => 'pages', 'id' => $id]);

        $this->assertSame(self::MARKUP, $result['content']['raw']);
        $this->assertArrayNotHasKey('rendered', $result['content']);
    }

    public function test_read_returns_rendered_html_on_request(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => self::MARKUP]);

        $result = $this->assertAbilitySuccess('gds/content-read', ['type' => 'pages', 'id' => $id, 'rendered' => true]);

        $this->assertSame(self::MARKUP, $result['content']['raw']);
        $this->assertStringContainsString('<strong>world</strong>', $result['content']['rendered']);
    }

    public function test_read_falls_back_to_rendered_html_for_readers_who_may_not_edit(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => self::MARKUP]);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $result = $this->executeAbility('gds/content-read', ['type' => 'pages', 'id' => $id]);
        if (is_wp_error($result)) {
            $this->markTestSkipped('Contributors may not use content-read here: '.$result->get_error_message());
        }

        $this->assertArrayNotHasKey('raw', $result['content']);
        $this->assertStringContainsString('<strong>world</strong>', $result['content']['rendered']);
    }

    public function test_quiet_update_returns_only_a_summary(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_status' => 'draft']);

        $result = $this->assertAbilitySuccess('gds/content-update', [
            'type' => 'pages',
            'id' => $id,
            'content' => self::MARKUP,
            'quiet' => true,
        ]);

        $this->assertEqualsCanonicalizing(['id', 'status', 'modified', 'link', 'edit_url'], array_keys(array_diff_key($result, ['_undo' => 1])));
        $this->assertSame(self::MARKUP, get_post_field('post_content', $id));
    }

    public function test_quiet_create_returns_only_a_summary(): void
    {
        $result = $this->assertAbilitySuccess('gds/content-create', [
            'type' => 'pages',
            'title' => 'Quiet',
            'content' => self::MARKUP,
            'quiet' => true,
        ]);

        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame(self::MARKUP, get_post_field('post_content', $result['id']));
    }

    public function test_quiet_replace_leaves_out_the_content(): void
    {
        $id = $this->createPost(['post_type' => 'page', 'post_content' => self::MARKUP]);

        $result = $this->assertAbilitySuccess('gds/content-replace', [
            'id' => $id,
            'search' => 'world',
            'replace' => 'there',
            'quiet' => true,
        ]);

        $this->assertSame(1, $result['replaced_count']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertStringContainsString('there', get_post_field('post_content', $id));
    }

    public function test_writes_carry_undo_for_gds_assistant(): void
    {
        $id = $this->createPost(['post_type' => 'page']);

        $result = $this->assertAbilitySuccess('gds/content-update', ['type' => 'pages', 'id' => $id, 'title' => 'New']);

        $this->assertArrayHasKey('_undo', $result);
    }

    public function test_writes_over_mcp_leave_out_undo(): void
    {
        $id = $this->createPost(['post_type' => 'page']);
        $this->simulateMcpRequest();

        $result = $this->assertAbilitySuccess('gds/content-update', ['type' => 'pages', 'id' => $id, 'title' => 'New']);

        $this->assertArrayNotHasKey('_undo', $result);
    }
}
