<?php

namespace Automattic\WooCommerce\Internal\MCP\Transport;

/**
 * Stand-in for WooCommerce's MCP transport when WooCommerce is not installed:
 * only the API-key permission accessor the bridge reads.
 */
class WooCommerceRestTransport
{
    public static ?string $permissions = null;

    public static function get_current_user_permissions(): ?string
    {
        return self::$permissions;
    }
}
