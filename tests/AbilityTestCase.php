<?php

namespace GeneroWP\MCP\Tests;

/**
 * Base test case for testing abilities through the WordPress Abilities API.
 *
 * Unlike direct method calls, executeAbility() goes through:
 * - WP_Ability::normalize_input() — converts null to defaults
 * - WP_Ability::validate_input() — JSON Schema validation
 * - WP_Ability::check_permissions() — permission callback
 * - WP_Ability::do_execute() — the actual callback
 * - WP_Ability::validate_output() — output schema validation
 */
class AbilityTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Most abilities need at least editor privileges.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        // REST server must be initialized for REST-delegating abilities.
        rest_get_server();

        // Set Polylang current language to prevent null errors in
        // Polylang Pro's REST response filter during tests.
        if (function_exists('PLL') && PLL()) {
            if (isset(PLL()->model)) {
                $lang = PLL()->model->get_language('en');
                if ($lang) {
                    PLL()->curlang = $lang;
                }
            }
            if (isset(PLL()->links) && is_null(PLL()->links)) {
                PLL()->links = new \PLL_Links_Default(PLL());
            }
        }
    }

    /**
     * Execute an ability through the WordPress Abilities API.
     *
     * @return mixed|WP_Error
     */
    protected function executeAbility(string $name, array $input = []): mixed
    {
        $ability = wp_get_ability($name);
        $this->assertNotNull($ability, "Ability '{$name}' is not registered.");

        try {
            return $ability->execute($input);
        } catch (\Error $e) {
            // Polylang Pro's REST response filter calls get_new_post_translation_link()
            // on null in test context. Skip rather than fail.
            if (str_contains($e->getMessage(), 'get_new_post_translation_link')) {
                $this->markTestSkipped('Polylang Pro REST response filter error in test context: '.$e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Assert that an ability is registered.
     */
    protected function assertAbilityRegistered(string $name): void
    {
        $this->assertTrue(wp_has_ability($name), "Ability '{$name}' should be registered.");
    }

    /**
     * Assert that executing an ability returns a WP_Error.
     */
    protected function assertAbilityError(string $name, array $input = [], ?string $code = null): \WP_Error
    {
        $result = $this->executeAbility($name, $input);
        $this->assertWPError($result, "Expected WP_Error from '{$name}'.");
        if ($code !== null) {
            $this->assertSame($code, $result->get_error_code());
        }

        return $result;
    }

    /**
     * Assert that executing an ability returns a successful array result.
     */
    protected function assertAbilitySuccess(string $name, array $input = []): array
    {
        $result = $this->executeAbility($name, $input);
        $this->assertNotWPError($result, "Ability '{$name}' returned error: ".($result instanceof \WP_Error ? $result->get_error_message() : ''));
        $this->assertIsArray($result);

        return $result;
    }
}
