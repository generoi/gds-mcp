<?php

namespace GeneroWP\MCP\Integrations\Redirects;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Integrations\Redirects\Providers\Redirection;
use GeneroWP\MCP\Integrations\Redirects\Providers\SafeRedirectManager;
use GeneroWP\MCP\Integrations\Redirects\Providers\YoastRedirects;
use WP_Error;

/**
 * Manages redirects via whichever plugin is available.
 * Priority: Safe Redirect Manager → Redirection → Yoast SEO (post meta).
 */
final class ManageRedirectsAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/redirects-manage', [
            'label' => 'Manage Redirects',
            'description' => 'List or create URL redirects. Auto-detects the available plugin: Safe Redirect Manager, Redirection, or Yoast SEO (per-post redirects only).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform.',
                        'enum' => ['list', 'create'],
                    ],
                    'from' => [
                        'type' => 'string',
                        'description' => 'URL path to redirect from (for create). E.g. /old-page. For Yoast provider, use a post ID or permalink.',
                    ],
                    'to' => [
                        'type' => 'string',
                        'description' => 'URL to redirect to (for create). E.g. /new-page or full URL.',
                    ],
                    'status_code' => [
                        'type' => 'integer',
                        'description' => 'HTTP status code (not supported by Yoast provider).',
                        'default' => 301,
                        'enum' => [301, 302, 307, 410],
                    ],
                    'notes' => [
                        'type' => 'string',
                        'description' => 'Notes/title for the redirect.',
                    ],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'provider' => ['type' => 'string'],
                    'redirects' => ['type' => 'array'],
                    'redirect' => ['type' => 'object'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('manage_options')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to manage redirects.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $action = $input['action'] ?? '';
        $provider = self::getProvider();

        if (! $provider) {
            return new WP_Error('no_redirect_plugin', 'No supported redirect plugin found.');
        }

        return match ($action) {
            'list' => $provider::list(),
            'create' => self::handleCreate($provider, $input),
            default => new WP_Error('invalid_action', 'Action must be list or create.'),
        };
    }

    /**
     * @return class-string<SafeRedirectManager|Redirection|YoastRedirects>|null
     */
    private static function getProvider(): ?string
    {
        if (SafeRedirectManager::isAvailable()) {
            return SafeRedirectManager::class;
        }

        if (Redirection::isAvailable()) {
            return Redirection::class;
        }

        if (YoastRedirects::isAvailable()) {
            return YoastRedirects::class;
        }

        return null;
    }

    private static function handleCreate(string $provider, array $input): array|WP_Error
    {
        $from = $input['from'] ?? '';
        $to = $input['to'] ?? '';

        if (empty($from) || empty($to)) {
            return new WP_Error('missing_fields', 'Both "from" and "to" are required.');
        }

        // Validate "from" is a path or post ID (not a full external URL).
        if (! is_numeric($from) && ! str_starts_with($from, '/')) {
            return new WP_Error('invalid_from', '"from" must be a relative path (e.g. /old-page) or a post ID.');
        }

        // Validate "to" is a URL or relative path.
        if (! str_starts_with($to, '/') && ! filter_var($to, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_to', '"to" must be a valid URL or relative path.');
        }

        return $provider::create($from, $to, $input);
    }
}
