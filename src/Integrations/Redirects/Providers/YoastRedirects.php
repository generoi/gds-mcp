<?php

namespace GeneroWP\MCP\Integrations\Redirects\Providers;

use WP_Error;

final class YoastRedirects
{
    public static function isAvailable(): bool
    {
        return defined('WPSEO_VERSION');
    }

    public static function list(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as redirect_to
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
             AND pm.meta_value != ''
             ORDER BY p.post_date DESC
             LIMIT 200",
            '_yoast_wpseo_redirect'
        ));

        $redirects = array_map(fn ($row) => [
            'id' => (int) $row->ID,
            'from' => get_permalink($row->ID),
            'to' => $row->redirect_to,
            'status_code' => 301,
            'notes' => $row->post_title,
        ], $results ?: []);

        return ['provider' => 'yoast', 'redirects' => $redirects];
    }

    public static function create(string $from, string $to, array $input): array|WP_Error
    {
        // For Yoast, "from" must be a post ID or permalink.
        $postId = is_numeric($from) ? (int) $from : url_to_postid($from);

        if (! $postId || ! get_post($postId)) {
            return new WP_Error('invalid_post', 'Yoast redirects require a valid post ID or URL that maps to a post.');
        }

        update_post_meta($postId, '_yoast_wpseo_redirect', esc_url_raw($to));

        return [
            'provider' => 'yoast',
            'redirect' => [
                'id' => $postId,
                'from' => get_permalink($postId),
                'to' => $to,
                'status_code' => 301,
            ],
        ];
    }
}
