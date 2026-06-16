<?php

namespace GeneroWP\MCP;

use Genero\Sage\CacheTags\CacheTags;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;

class Plugin
{
    protected static ?self $instance = null;

    public static function getInstance(): self
    {
        if (! isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function __construct()
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

        // Enforce least-privilege capabilities on gds/* abilities before they
        // register (they default to __return_true, deferring all auth to the
        // chat-level gate). Must be hooked before wp_abilities_api_init fires.
        Abilities\CapabilityPolicy::register();

        // Undo: handle restore requests from gds-assistant (snapshots captured
        // by mutating abilities and replayed via the gds-mcp/restore_snapshot
        // filter).
        Undo\RestoreSnapshot::register();
        add_action('plugins_loaded', [$this, 'bootstrapMcpAdapter'], 20);

        // Clear cached schemas when plugins change (abilities may differ)
        add_action('activated_plugin', [self::class, 'clearSchemaCache']);
        add_action('deactivated_plugin', [self::class, 'clearSchemaCache']);

        // WooCommerce — bridge its built-in abilities into the global registry
        // so they're available outside the /woocommerce/mcp endpoint. Deferred
        // because WC may load after gds-mcp on the plugins_loaded cycle.
        add_action('plugins_loaded', [Integrations\WooCommerce\AbilitiesBridge::class, 'register'], 20);
    }

    public function bootstrapMcpAdapter(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        $adapterClass = '\\WP\\MCP\\Core\\McpAdapter';
        if (! class_exists($adapterClass)) {
            return;
        }

        $adapterClass::instance();
    }

    public static function clearSchemaCache(): void
    {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_gds_mcp_input_schema').'%'
            )
        );
    }

    public function registerCategory(): void
    {
        if (! function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category('gds-content', [
            'label' => 'Content Management',
            'description' => 'Abilities for reading, creating, and managing content and translations',
        ]);
    }

    public function registerAbilities(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        Abilities\HelpAbility::register();

        // Generic CRUD: 10 tools (content-list/read/create/update/delete + terms-*)
        // instead of 5 per type (150+ tools). Stays under OpenAI's 128 tool limit.
        Abilities\GenericPostTypeAbility::register();
        Abilities\GenericTaxonomyAbility::register();

        // Custom abilities (no REST equivalent)
        Abilities\NavMenuItemsAbility::register();
        Abilities\MediaUploadAbility::register();
        Abilities\DuplicatePostAbility::register();
        Abilities\BulkUpdatePostsAbility::register();
        Abilities\ManageRevisionsAbility::register();
        Abilities\BlockCatalogResource::register();
        Abilities\GetBlockAbility::register();
        Abilities\PatchBlockAbility::register();
        Abilities\ReplaceContentAbility::register();
        Abilities\SiteMapResource::register();
        Abilities\ThemeJsonResource::register();
        Abilities\WebFetchAbility::register();
        Abilities\MailSendAbility::register();

        // ACF -- only if active
        if (function_exists('acf_get_field_groups')) {
            Abilities\AcfFieldsResource::register();
        }

        // Polylang -- only if active
        if (function_exists('pll_get_post_language')) {
            Integrations\Polylang\ListLanguagesAbility::register();
            Integrations\Polylang\CreateTranslationAbility::register();
            Integrations\Polylang\LinkTranslationsAbility::register();
            Integrations\Polylang\CreateTermTranslationAbility::register();
            Integrations\Polylang\TranslationAuditAbility::register();
            Integrations\Polylang\ListStringTranslationsAbility::register();
            Integrations\Polylang\UpdateStringTranslationAbility::register();

            // Machine translation -- only if Polylang Pro module is available.
            if (class_exists(Factory::class)) {
                Integrations\Polylang\MachineTranslateAbility::register();
            }
        }

        // Gravity Forms -- only if REST API is available
        if (class_exists('GFAPI')) {
            Integrations\GravityForms\GravityFormsAbility::register();
            Integrations\GravityForms\FeedsAbility::register();
        }

        // Cache clearing -- via sage-cachetags (abstracts Kinsta, Fastly, etc.)
        if (class_exists(CacheTags::class)) {
            Integrations\CacheTags\ClearCacheAbility::register();
        }

        // Stream -- activity log
        if (class_exists(\WP_Stream\Plugin::class)) {
            Integrations\Stream\QueryActivityLogAbility::register();
        }

        // Redirects -- if any supported redirect plugin is active
        if (function_exists('srm_create_redirect') || class_exists('Red_Item') || defined('WPSEO_VERSION')) {
            Integrations\Redirects\ManageRedirectsAbility::register();
        }
    }
}
