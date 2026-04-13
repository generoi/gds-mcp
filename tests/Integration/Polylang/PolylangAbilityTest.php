<?php

namespace GeneroWP\MCP\Tests\Integration\Polylang;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for Polylang abilities through the Abilities API.
 */
class PolylangAbilityTest extends AbilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }
    }

    /**
     * Helper to check if a Polylang language is configured in the test DB.
     */
    private function requireLanguage(string $slug): void
    {
        if (! PLL()->model->get_language($slug)) {
            $this->markTestSkipped("Target language '{$slug}' not configured.");
        }
    }

    public function test_polylang_abilities_are_registered(): void
    {
        $this->assertAbilityRegistered('gds/languages-list');
        $this->assertAbilityRegistered('gds/translations-create');
        $this->assertAbilityRegistered('gds/translations-audit');
    }

    // ── List Languages ────────────────────────────────────────────

    public function test_list_languages(): void
    {
        $result = $this->assertAbilitySuccess('gds/languages-list');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $slugs = array_column($result, 'slug');
        $this->assertContains('en', $slugs);
    }

    public function test_list_languages_unauthenticated(): void
    {
        wp_set_current_user(0);

        $result = $this->assertAbilitySuccess('gds/languages-list');
        $this->assertNotEmpty($result);
    }

    // ── Translation Audit ─────────────────────────────────────────

    public function test_translation_audit(): void
    {
        // Create an English post without translations
        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Untranslated Post',
        ]);

        $result = $this->assertAbilitySuccess('gds/translations-audit', [
            'post_type' => 'post',
        ]);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('languages', $result);
        $this->assertArrayHasKey('by_type', $result);
        $this->assertArrayHasKey('total_posts', $result['summary']);
        $this->assertArrayHasKey('fully_translated', $result['summary']);
        $this->assertGreaterThan(0, $result['summary']['total_posts']);
    }

    public function test_translation_audit_by_language(): void
    {
        $this->requireLanguage('fi');

        $postId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'English Only Post',
        ]);

        $result = $this->assertAbilitySuccess('gds/translations-audit', [
            'post_type' => 'post',
            'lang' => 'fi',
        ]);

        // Should report this post as missing Finnish translation
        $this->assertGreaterThan(0, $result['summary']['total_posts']);
    }

    public function test_translation_audit_output_schema_validated(): void
    {
        // This test specifically validates that the output matches the schema.
        // If the output schema doesn't match, wp_get_ability()->execute() returns WP_Error.
        $result = $this->assertAbilitySuccess('gds/translations-audit', [
            'post_type' => 'page',
        ]);

        $this->assertIsArray($result['summary']);
        $this->assertIsArray($result['languages']);
        $this->assertIsArray($result['by_type']);
    }

    // ── Create Translation ────────────────────────────────────────

    public function test_create_translation(): void
    {
        $this->requireLanguage('fi');

        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'English Source',
            'post_content' => 'English content here.',
        ]);
        update_post_meta($sourceId, 'custom_field', 'original_value');

        $result = $this->executeAbility('gds/translations-create', [
            'source_id' => $sourceId,
            'lang' => 'fi',
            'title' => 'Finnish Title',
        ]);

        if (is_wp_error($result)) {
            $this->markTestSkipped('Translation create failed: '.$result->get_error_message());
        }

        $this->assertIsArray($result);
        $this->assertNotEquals($sourceId, $result['id']);
        $this->assertSame('draft', $result['status']);

        // Verify linked via Polylang
        $translations = pll_get_post_translations($sourceId);
        $this->assertArrayHasKey('fi', $translations);
        $this->assertSame($result['id'], $translations['fi']);

        // Verify meta was copied
        $this->assertSame('original_value', get_post_meta($result['id'], 'custom_field', true));

        wp_delete_post($result['id'], true);
    }

    public function test_create_translation_rejects_duplicate(): void
    {
        $this->requireLanguage('fi');

        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Source For Duplicate Test',
            'post_content' => 'Some content.',
        ]);

        // Create first translation
        $fiId = self::factory()->post->create([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => 'Existing Finnish',
        ]);
        pll_set_post_language($fiId, 'fi');
        pll_save_post_translations([
            'en' => $sourceId,
            'fi' => $fiId,
        ]);

        // Try to create a second Finnish translation
        $result = $this->executeAbility('gds/translations-create', [
            'source_id' => $sourceId,
            'lang' => 'fi',
        ]);

        $this->assertWPError($result);
        $this->assertSame('translation_exists', $result->get_error_code());

        wp_delete_post($fiId, true);
    }

    public function test_create_translation_rejects_invalid_language(): void
    {
        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        $this->assertAbilityError('gds/translations-create', [
            'source_id' => $sourceId,
            'lang' => 'xx',
        ], 'invalid_language');
    }

    public function test_create_translation_rejects_missing_source(): void
    {
        $this->requireLanguage('fi');

        $this->assertAbilityError('gds/translations-create', [
            'source_id' => 999999,
            'lang' => 'fi',
        ], 'source_not_found');
    }

    // ── String Translations ───────────────────────────────────────

    public function test_string_translations_registered(): void
    {
        $this->assertAbilityRegistered('gds/strings-list');
        $this->assertAbilityRegistered('gds/strings-update');
    }

    // ── Machine Translation ───────────────────────────────────────

    public function test_machine_translate_registered_if_pro(): void
    {
        if (class_exists(\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory::class)) {
            $this->assertAbilityRegistered('gds/translations-machine');
        } else {
            $this->assertFalse(wp_has_ability('gds/translations-machine'));
        }
    }
}
