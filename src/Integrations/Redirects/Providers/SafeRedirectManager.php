<?php

namespace GeneroWP\MCP\Integrations\Redirects\Providers;

use WP_Error;
use WP_Query;

final class SafeRedirectManager
{
    public static function isAvailable(): bool
    {
        return function_exists('srm_create_redirect');
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(): array
    {
        $query = new WP_Query([
            'post_type' => 'redirect_rule',
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $redirects = array_map(fn ($post) => [
            'id' => $post->ID,
            'from' => get_post_meta($post->ID, '_redirect_rule_from', true),
            'to' => get_post_meta($post->ID, '_redirect_rule_to', true),
            'status_code' => (int) get_post_meta($post->ID, '_redirect_rule_status_code', true),
            'notes' => $post->post_excerpt,
        ], $query->posts);

        return ['provider' => 'safe-redirect-manager', 'redirects' => $redirects];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|WP_Error
     */
    public static function create(string $from, string $to, array $input): array|WP_Error
    {
        $postId = srm_create_redirect(
            $from,
            $to,
            $input['status_code'] ?? 301,
            false,
            'publish',
            0,
            $input['notes'] ?? ''
        );

        if (is_wp_error($postId)) {
            return $postId;
        }

        return [
            'provider' => 'safe-redirect-manager',
            'redirect' => [
                'id' => $postId,
                'from' => $from,
                'to' => $to,
                'status_code' => $input['status_code'] ?? 301,
            ],
            // Undo data for the ability to peel off; trashes the created post.
            '_undo_data' => ['provider' => 'srm', 'post_id' => (int) $postId],
        ];
    }
}
