<?php

namespace GeneroWP\MCP\Integrations\Redirects;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Integrations\Redirects\Providers\Redirection;
use GeneroWP\MCP\Integrations\Redirects\Providers\SafeRedirectManager;
use GeneroWP\MCP\Integrations\Redirects\Providers\YoastRedirects;
use GeneroWP\MCP\Undo\Reversible;
use WP_Error;

/**
 * Manages redirects via whichever plugin is available.
 * Priority: Safe Redirect Manager → Redirection → Yoast SEO (post meta).
 */
final class ManageRedirectsAbility
{
    use Reversible;

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
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Redirects per page for list (default 50, 5–200).',
                        'default' => 50,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Page number for list (1-based).',
                        'default' => 1,
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
                    'total' => ['type' => 'integer'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'redirect' => ['type' => 'object'],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $action = $input['action'] ?? '';
        $provider = self::getProvider();

        if (! $provider) {
            return new WP_Error('no_redirect_plugin', 'No supported redirect plugin found.');
        }

        if (! current_user_can('edit_others_posts')) {
            return new WP_Error('forbidden', 'You do not have permission to manage redirects.', ['status' => 403]);
        }

        return match ($action) {
            'list' => self::handleList($provider, $input),
            'create' => $this->handleCreate($provider, $input),
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

    /**
     * @param  class-string<SafeRedirectManager|Redirection|YoastRedirects>  $provider
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function handleList(string $provider, array $input): array
    {
        $perPage = max(5, min(200, (int) ($input['per_page'] ?? 50)));
        $page = max(1, (int) ($input['page'] ?? 1));

        return $provider::list($perPage, $page) + ['page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|WP_Error
     */
    private function handleCreate(string $provider, array $input): array|WP_Error
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

        $result = $provider::create($from, $to, $input);
        if (is_wp_error($result)) {
            return $result;
        }

        // Each provider returns provider-aware undo data under `_undo_data`;
        // peel it off so it never reaches the LLM, then attach the undo envelope.
        $undo = $result['_undo_data'] ?? null;
        unset($result['_undo_data']);

        if (is_array($undo)) {
            return $this->reversible($result, 'restore-redirect', $undo, 'Remove the created redirect');
        }

        return $result;
    }
}
