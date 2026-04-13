<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/terms-* abilities through the Abilities API.
 */
class TermsAbilityTest extends AbilityTestCase
{
    public function test_terms_abilities_are_registered(): void
    {
        $this->assertAbilityRegistered('gds/terms-list');
        $this->assertAbilityRegistered('gds/terms-read');
        $this->assertAbilityRegistered('gds/terms-create');
        $this->assertAbilityRegistered('gds/terms-update');
        $this->assertAbilityRegistered('gds/terms-delete');
    }

    // ── List ──────────────────────────────────────────────────────

    public function test_list_categories(): void
    {
        self::factory()->category->create(['name' => 'Test Category Alpha']);
        self::factory()->category->create(['name' => 'Test Category Beta']);

        $result = $this->assertAbilitySuccess('gds/terms-list', [
            'taxonomy' => 'categories',
            'hide_empty' => false,
        ]);

        $this->assertIsArray($result);
        // At least the 2 we created + default "Uncategorized"
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    public function test_list_tags(): void
    {
        self::factory()->tag->create(['name' => 'integration-test-tag']);

        $result = $this->assertAbilitySuccess('gds/terms-list', [
            'taxonomy' => 'tags',
            'hide_empty' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(1, count($result));
    }

    public function test_list_rejects_invalid_taxonomy(): void
    {
        $this->assertAbilityError('gds/terms-list', [
            'taxonomy' => 'nonexistent_tax',
        ]);
    }

    public function test_list_rejects_missing_taxonomy(): void
    {
        $result = $this->executeAbility('gds/terms-list', []);
        $this->assertWPError($result);
    }

    public function test_list_with_search(): void
    {
        self::factory()->category->create(['name' => 'Unique Search Term Zeta']);

        $result = $this->assertAbilitySuccess('gds/terms-list', [
            'taxonomy' => 'categories',
            'search' => 'Unique Search Term Zeta',
            'hide_empty' => false,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('Unique Search Term Zeta', $result[0]['name']);
    }

    // ── Read ──────────────────────────────────────────────────────

    public function test_read_category(): void
    {
        $termId = self::factory()->category->create(['name' => 'Readable Category']);

        $result = $this->assertAbilitySuccess('gds/terms-read', [
            'taxonomy' => 'categories',
            'id' => $termId,
        ]);

        $this->assertSame($termId, $result['id']);
        $this->assertSame('Readable Category', $result['name']);
    }

    public function test_read_nonexistent_term(): void
    {
        $this->assertAbilityError('gds/terms-read', [
            'taxonomy' => 'categories',
            'id' => 999999,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function test_create_category(): void
    {
        $result = $this->assertAbilitySuccess('gds/terms-create', [
            'taxonomy' => 'categories',
            'name' => 'Created via Ability API',
        ]);

        $this->assertSame('Created via Ability API', $result['name']);
        $this->assertNotEmpty($result['id']);
    }

    public function test_create_tag(): void
    {
        $result = $this->assertAbilitySuccess('gds/terms-create', [
            'taxonomy' => 'tags',
            'name' => 'ability-api-tag',
        ]);

        $this->assertSame('ability-api-tag', $result['name']);
    }

    public function test_create_with_description(): void
    {
        $result = $this->assertAbilitySuccess('gds/terms-create', [
            'taxonomy' => 'categories',
            'name' => 'Described Category',
            'description' => 'A category with a description.',
        ]);

        $this->assertSame('A category with a description.', $result['description']);
    }

    // ── Update ────────────────────────────────────────────────────

    public function test_update_category_name(): void
    {
        $termId = self::factory()->category->create(['name' => 'Before']);

        $result = $this->assertAbilitySuccess('gds/terms-update', [
            'taxonomy' => 'categories',
            'id' => $termId,
            'name' => 'After',
        ]);

        $this->assertSame('After', $result['name']);
    }

    // ── Delete ────────────────────────────────────────────────────

    public function test_delete_category(): void
    {
        $termId = self::factory()->category->create(['name' => 'To Delete']);

        $result = $this->assertAbilitySuccess('gds/terms-delete', [
            'taxonomy' => 'categories',
            'id' => $termId,
            'force' => true,
        ]);

        $this->assertIsArray($result);
        $this->assertNull(term_exists($termId));
    }

    // ── Permissions ───────────────────────────────────────────────

    public function test_create_blocked_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertAbilityError('gds/terms-create', [
            'taxonomy' => 'categories',
            'name' => 'Should Fail',
        ]);
    }
}
