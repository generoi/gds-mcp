<?php

namespace GeneroWP\MCP\Http;

use GeneroWP\MCP\OAuth\BearerAuth;
use GeneroWP\MCP\Undo\McpRequest;

/**
 * Whether an ability is being run by a remote agent rather than through
 * gds-assistant.
 *
 * gds-assistant asks the person before every mail-send and every web-fetch to
 * an untrusted host (`gds-assistant/tool_requires_approval`). A remote client
 * has no such step: whatever its model decides runs, so a prompt injection in
 * content it reads (a form entry, a fetched page) could mail data out or put
 * it in a URL. The abilities refuse those calls here instead.
 *
 * Remote means any of: an MCP adapter route or the STDIO transport, a bearer
 * token from the OAuth module, or an application password, which reaches
 * core's `/wp-abilities/v1` routes as well as the MCP endpoint.
 */
final class RemoteClient
{
    public static function isActive(): bool
    {
        if (McpRequest::isActive() || BearerAuth::authenticated()) {
            return true;
        }

        return function_exists('rest_get_authenticated_app_password')
            && rest_get_authenticated_app_password() !== null;
    }
}
