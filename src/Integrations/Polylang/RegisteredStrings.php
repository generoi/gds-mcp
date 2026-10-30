<?php

namespace GeneroWP\MCP\Integrations\Polylang;

/**
 * The strings registered for translation, in any request.
 *
 * pll_register_string() only records a string when Polylang runs its admin
 * context; in a REST request (the MCP endpoint) or under WP-CLI (the STDIO
 * transport) it does nothing, so the strings themes register on `init` were
 * missing from gds/strings-list and machine translation. The list seen in
 * wp-admin is kept in an option and registered with Polylang everywhere
 * else — registered, not just listed, because Polylang Pro's import only
 * saves a translation for a string it has registered in that request.
 */
final class RegisteredStrings
{
    private const OPTION = 'gds_mcp_pll_registered_strings';

    public static function register(): void
    {
        // Last, so strings anything registers on admin_init are in.
        add_action('admin_init', [self::class, 'remember'], PHP_INT_MAX);
    }

    public static function remember(): void
    {
        if (wp_doing_ajax() || ! class_exists('PLL_Admin_Strings') || ! function_exists('PLL') || ! PLL() instanceof \PLL_Admin_Base) {
            return;
        }

        // Replaced whole, so strings no longer registered drop out; but only
        // when the set of strings changed, not when a label differs because
        // this admin user has another locale.
        $strings = \PLL_Admin_Strings::get_strings();
        if (self::fingerprint($strings) !== self::fingerprint((array) get_option(self::OPTION, []))) {
            update_option(self::OPTION, $strings, false);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (! PLL() instanceof \PLL_Admin_Base) {
            foreach ((array) get_option(self::OPTION, []) as $string) {
                \PLL_Admin_Strings::register_string(
                    (string) ($string['name'] ?? ''),
                    (string) ($string['string'] ?? ''),
                    (string) ($string['context'] ?? 'Polylang'),
                    (bool) ($string['multiline'] ?? false),
                );
            }
        }

        return \PLL_Admin_Strings::get_strings();
    }

    /**
     * Run a translation through the filter Polylang's strings table applies
     * before saving it (kses for users without unfiltered_html, and whatever
     * the plugin that registered the string adds).
     */
    public static function sanitizeTranslation(string $string, string $translation, string $previous): string
    {
        // Polylang hooks its default sanitizer only on its settings pages.
        \PLL_Admin_Strings::init();
        $registered = self::all()[md5($string)] ?? [];

        return (string) apply_filters('pll_sanitize_string_translation', $translation, (string) ($registered['name'] ?? ''), (string) ($registered['context'] ?? ''), $string, $previous);
    }

    /**
     * Which strings, in which groups: Polylang keys them by the md5 of the
     * string, and the name is a label that may be translated.
     *
     * @param  array<mixed>  $strings
     * @return array<string, string>
     */
    private static function fingerprint(array $strings): array
    {
        $fingerprint = array_map(fn ($string) => (string) ($string['context'] ?? ''), $strings);
        ksort($fingerprint);

        return $fingerprint;
    }
}
