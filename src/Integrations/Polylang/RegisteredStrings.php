<?php

namespace GeneroWP\MCP\Integrations\Polylang;

/**
 * The strings registered for translation, in any request.
 *
 * pll_register_string() only records a string when Polylang runs its admin
 * context; in a REST request (the MCP endpoint) or under WP-CLI (the STDIO
 * transport) it does nothing, so the strings themes register on `init` were
 * missing from gds/strings-list and machine translation. The list seen in
 * wp-admin is kept in an option and merged in everywhere else.
 */
final class RegisteredStrings
{
    private const OPTION = 'gds_mcp_pll_registered_strings';

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'remember']);
    }

    public static function remember(): void
    {
        if (! class_exists('PLL_Admin_Strings') || ! function_exists('PLL') || ! PLL() instanceof \PLL_Admin_Base) {
            return;
        }

        $strings = \PLL_Admin_Strings::get_strings();
        if ($strings !== get_option(self::OPTION)) {
            update_option(self::OPTION, $strings, false);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $strings = \PLL_Admin_Strings::get_strings();
        if (PLL() instanceof \PLL_Admin_Base) {
            return $strings;
        }

        // Keyed by the md5 of the string, as Polylang keys them.
        return $strings + (array) get_option(self::OPTION, []);
    }
}
