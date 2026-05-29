<?php

namespace GeneroWP\MCP\Integrations\Redirects\Providers;

use Red_Item;
use WP_Error;

final class Redirection
{
    public static function isAvailable(): bool
    {
        return class_exists(Red_Item::class);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(): array
    {
        $result = Red_Item::get_filtered([
            'per_page' => 200,
            'page' => 0,
        ]);

        // get_filtered() returns items as plain arrays via to_json().
        $redirects = array_map(fn (array $item) => [
            'id' => (int) $item['id'],
            'from' => $item['url'] ?? '',
            'to' => $item['action_data'] ?? '',
            'status_code' => (int) ($item['action_code'] ?? 301),
            'notes' => $item['title'] ?? '',
            'hits' => (int) ($item['hits'] ?? 0),
            'enabled' => ! empty($item['enabled']),
        ], $result['items'] ?? []);

        return ['provider' => 'redirection', 'redirects' => $redirects];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|WP_Error
     */
    public static function create(string $from, string $to, array $input): array|WP_Error
    {
        $redirect = Red_Item::create([
            'url' => $from,
            'action_data' => ['url' => $to],
            'action_code' => $input['status_code'] ?? 301,
            'action_type' => 'url',
            'match_type' => 'url',
            'group_id' => 1,
            'title' => $input['notes'] ?? '',
        ]);

        if (is_wp_error($redirect)) {
            return $redirect;
        }

        return [
            'provider' => 'redirection',
            'redirect' => [
                'id' => $redirect->get_id(),
                'from' => $redirect->get_url(),
                'to' => $to,
                'status_code' => $redirect->get_action_code(),
            ],
            // Undo data for the ability to peel off; deletes the created item.
            '_undo_data' => ['provider' => 'redirection', 'item_id' => (int) $redirect->get_id()],
        ];
    }
}
