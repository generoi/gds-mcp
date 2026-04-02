<?php

namespace GeneroWP\MCP;

use Genero\Sage\CacheTags\CacheTags;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;

class Plugin
{
    protected static $instance;

    public static function getInstance(): static
    {
        if (! isset(self::$instance)) {
            self::$instance = new static;
        }

        return self::$instance;
    }

    public function __construct()
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
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

        // Core -- always available
        Abilities\ListPostTypesAbility::register();
        Abilities\CreatePostAbility::register();
        Abilities\ReadPostAbility::register();
        Abilities\ListPostsAbility::register();
        Abilities\UpdatePostContentAbility::register();
        Abilities\SearchMediaAbility::register();
        Abilities\UploadMediaAbility::register();
        Abilities\ListMenusAbility::register();
        Abilities\GetMenuAbility::register();
        Abilities\AddMenuItemAbility::register();
        Abilities\ManageTermsAbility::register();
        Abilities\DuplicatePostAbility::register();
        Abilities\BulkUpdatePostsAbility::register();
        Abilities\ManageRevisionsAbility::register();
        Abilities\BlockCatalogResource::register();
        Abilities\GetBlockAbility::register();
        Abilities\SiteMapResource::register();
        Abilities\ThemeJsonResource::register();
        Abilities\CssVarsResource::register();

        // ACF -- only if active
        if (function_exists('acf_get_field_groups')) {
            Abilities\AcfFieldsResource::register();
        }

        // Polylang -- only if active
        if (function_exists('pll_get_post_language')) {
            Integrations\Polylang\CreateTranslationAbility::register();
            Integrations\Polylang\CreateTermTranslationAbility::register();
            Integrations\Polylang\TranslationAuditAbility::register();
            Integrations\Polylang\ListStringTranslationsAbility::register();
            Integrations\Polylang\UpdateStringTranslationAbility::register();

            // Machine translation -- only if Polylang Pro module is available.
            if (class_exists(Factory::class)) {
                Integrations\Polylang\MachineTranslateAbility::register();
            }
        }

        // Gravity Forms -- only if active
        if (class_exists('GFAPI')) {
            Integrations\GravityForms\ListFormsAbility::register();
            Integrations\GravityForms\GetFormAbility::register();
            Integrations\GravityForms\GetFormEntriesAbility::register();
            Integrations\GravityForms\CreateGravityFormAbility::register();
        }

        // Yoast SEO -- only if active
        if (defined('WPSEO_VERSION')) {
            Integrations\YoastSeo\GetSeoMetaAbility::register();
            Integrations\YoastSeo\UpdateSeoMetaAbility::register();
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
