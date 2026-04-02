<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ManageTermsAbility;
use WP_UnitTestCase;

class ManageTermsAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_list_terms(): void
    {
        $result = ManageTermsAbility::execute([
            'action' => 'list',
            'taxonomy' => 'category',
        ]);

        $this->assertArrayHasKey('terms', $result);
        $this->assertIsArray($result['terms']);
    }

    public function test_create_term(): void
    {
        $result = ManageTermsAbility::execute([
            'action' => 'create',
            'taxonomy' => 'category',
            'name' => 'New Category',
            'slug' => 'new-category',
        ]);

        $this->assertArrayHasKey('term', $result);
        $this->assertSame('New Category', $result['term']['name']);
        $this->assertSame('new-category', $result['term']['slug']);
    }

    public function test_update_term(): void
    {
        $term = wp_insert_term('Old Name', 'category');
        $result = ManageTermsAbility::execute([
            'action' => 'update',
            'taxonomy' => 'category',
            'term_id' => $term['term_id'],
            'name' => 'Updated Name',
        ]);

        $this->assertSame('Updated Name', $result['term']['name']);
    }

    public function test_invalid_taxonomy_returns_error(): void
    {
        $result = ManageTermsAbility::execute([
            'action' => 'list',
            'taxonomy' => 'nonexistent_taxonomy',
        ]);
        $this->assertWPError($result);
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = ManageTermsAbility::checkPermission();
        $this->assertWPError($result);
    }
}
