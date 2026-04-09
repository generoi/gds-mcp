<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * Revision management abilities.
 *
 * List and view delegate to REST API (/wp/v2/{type}/{id}/revisions).
 * Restore uses wp_restore_post_revision() (no REST equivalent).
 */
final class ManageRevisionsAbility
{
    use RestDelegation;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function register(): void
    {
        $ability = self::instance();

        HelpAbility::registerAbility('gds/revisions-list', [
            'label' => 'List Revisions',
            'description' => 'List revisions for a post. Delegates to the WordPress REST API.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'The post ID to list revisions for.'],
                ],
                'required' => ['post_id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'permission_callback' => '__return_true',
            'execute_callback' => [$ability, 'listRevisions'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/revisions-read', [
            'label' => 'Read Revision',
            'description' => 'Read a single revision with full content.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'The parent post ID.'],
                    'id' => ['type' => 'integer', 'description' => 'The revision ID.'],
                ],
                'required' => ['post_id', 'id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$ability, 'readRevision'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/revisions-restore', [
            'label' => 'Restore Revision',
            'description' => 'Restore a post to a previous revision. No REST equivalent — uses wp_restore_post_revision().',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The revision ID to restore.'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$ability, 'restoreRevision'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    public function listRevisions(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $postId = $input['post_id'] ?? 0;
        unset($input['post_id']);

        $route = $this->getRevisionRoute($postId);
        if (is_wp_error($route)) {
            return $route;
        }

        $response = self::restGet($route, $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : array_map(fn ($item) => (array) $item, $response->get_data());
    }

    public function readRevision(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $postId = $input['post_id'] ?? 0;
        $revisionId = $input['id'] ?? 0;
        unset($input['post_id'], $input['id']);

        $route = $this->getRevisionRoute($postId);
        if (is_wp_error($route)) {
            return $route;
        }

        $response = self::restGet("{$route}/{$revisionId}", $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function restoreRevision(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $revisionId = (int) ($input['id'] ?? 0);

        $revision = wp_get_post_revision($revisionId);
        if (! $revision) {
            return new WP_Error('revision_not_found', 'Revision not found.');
        }

        if (! function_exists('wp_restore_post_revision')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        $restoredId = wp_restore_post_revision($revisionId);

        if (! $restoredId) {
            return new WP_Error('restore_failed', 'Failed to restore revision.');
        }

        // Return the restored post via REST
        $parentId = $revision->post_parent;
        $post = get_post($parentId);
        $route = self::getRestRoute($post->post_type);
        if ($route) {
            $response = self::restGet("{$route}/{$parentId}");
            if (! self::isRestError($response)) {
                return (array) $response->get_data();
            }
        }

        return ['id' => $parentId, 'restored_from_revision' => $revisionId];
    }

    private function getRevisionRoute(int $postId): string|WP_Error
    {
        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $route = self::getRestRoute($post->post_type);
        if (! $route) {
            return new WP_Error('invalid_post_type', "Post type '{$post->post_type}' is not available via REST API.");
        }

        return "{$route}/{$postId}/revisions";
    }
}
