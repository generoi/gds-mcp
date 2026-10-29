<?php

namespace GeneroWP\MCP\Undo;

/**
 * Whether the current request is a call from a remote MCP client.
 *
 * `_undo` snapshots are meant for gds-assistant, which peels them off before
 * the result reaches its LLM. The MCP adapter has no such consumer: it passes
 * the whole result to the client, so a snapshot of a long post doubled the
 * size of every write. Over MCP the snapshot is left out instead.
 */
final class McpRequest
{
    public static function isActive(): bool
    {
        // The STDIO transport runs under WP-CLI, where gds-assistant never does.
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        $route = isset($GLOBALS['wp']->query_vars['rest_route'])
            ? untrailingslashit((string) $GLOBALS['wp']->query_vars['rest_route'])
            : '';

        return $route !== '' && in_array($route, self::routes(), true);
    }

    /**
     * @return list<string>
     */
    private static function routes(): array
    {
        $adapterClass = '\\WP\\MCP\\Core\\McpAdapter';
        if (! class_exists($adapterClass)) {
            return [];
        }

        $routes = [];
        foreach ($adapterClass::instance()->get_servers() as $server) {
            $routes[] = '/'.$server->get_server_route_namespace().'/'.$server->get_server_route();
        }

        return $routes;
    }
}
