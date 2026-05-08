<?php

namespace GeneroWP\MCP\Integrations\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use ReflectionMethod;

/**
 * Bridge WooCommerce's MCP abilities into the WordPress-wide abilities registry.
 *
 * WooCommerce ships abilities for products and orders (woocommerce/products-list,
 * woocommerce/orders-get, …) but gates registration behind a URL match for
 * `/woocommerce/mcp`, so they're invisible to other MCP servers and to in-process
 * callers. This class re-runs WC's own configuration through its public factory
 * so the abilities are always registered when:
 *
 *   - The `mcp_integration` feature flag is enabled in WooCommerce.
 *   - WC's bridge hasn't already registered them on `/woocommerce/mcp`.
 *
 * It also marks woocommerce/* abilities as `mcp.public=true` and grants
 * permission for in-process callers based on the current user's WC capabilities.
 * WC's own transport hooks the same permission filter at priority 10 with API-key
 * scoping; we leave its result alone when it has already allowed the request.
 */
final class AbilitiesBridge
{
    public static function register(): void
    {
        if (! self::isWooCommerceMcpAvailable()) {
            return;
        }

        add_action('wp_abilities_api_init', [self::class, 'registerAbilities'], 11);
        add_filter('wp_register_ability_args', [self::class, 'markWooCommerceAbilitiesPublic'], 10, 2);
        add_filter('woocommerce_check_rest_ability_permissions_for_method', [self::class, 'grantInProcessPermission'], 10, 3);
    }

    public static function registerAbilities(): void
    {
        if (! self::isFeatureEnabled()) {
            return;
        }
        // Skip if WC's own bridge already registered them on /woocommerce/mcp.
        if (function_exists('wp_get_ability') && wp_get_ability('woocommerce/products-list')) {
            return;
        }

        try {
            $reflection = new ReflectionMethod(
                '\\Automattic\\WooCommerce\\Internal\\Abilities\\AbilitiesRestBridge',
                'get_configurations'
            );
            $reflection->setAccessible(true);
            $factory = '\\Automattic\\WooCommerce\\Internal\\Abilities\\REST\\RestAbilityFactory';
            foreach ($reflection->invoke(null) as $config) {
                $factory::register_controller_abilities($config);
            }
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error(
                    '[gds-mcp] Failed to register WooCommerce abilities: '.$e->getMessage(),
                    ['source' => 'woocommerce-mcp']
                );
            }
        }
    }

    public static function markWooCommerceAbilitiesPublic(array $args, string $name): array
    {
        if (str_starts_with($name, 'woocommerce/')) {
            $args['meta']['mcp']['public'] = true;
        }

        return $args;
    }

    public static function grantInProcessPermission(bool $allowed, string $method, $controller): bool
    {
        if ($allowed) {
            return true;
        }
        $isRead = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);

        return current_user_can($isRead ? 'edit_others_shop_orders' : 'manage_woocommerce');
    }

    private static function isWooCommerceMcpAvailable(): bool
    {
        return class_exists('\\Automattic\\WooCommerce\\Internal\\Abilities\\AbilitiesRestBridge')
            && class_exists('\\Automattic\\WooCommerce\\Internal\\Abilities\\REST\\RestAbilityFactory')
            && class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil');
    }

    private static function isFeatureEnabled(): bool
    {
        return FeaturesUtil::feature_is_enabled('mcp_integration');
    }
}
