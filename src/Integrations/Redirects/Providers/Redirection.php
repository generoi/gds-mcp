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
        ];
    }
}
