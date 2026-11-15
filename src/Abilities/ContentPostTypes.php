<?php

namespace GeneroWP\MCP\Abilities;

/**
 * The post types and statuses the bulk and audit tools may query: the same
 * types the content-* tools expose (shown in REST, not menu items), and the
 * statuses a person picks in wp-admin.
 *
 * Without this, a raw post_type reaches WP_Query, and an editor could list or
 * change `user_request` posts, whose titles are the email addresses of
 * privacy requests, or anything else core and plugins keep out of REST.
 */
final class ContentPostTypes
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (get_post_types(['show_in_rest' => true], 'objects') as $type) {
            $restBase = $type->rest_base ?: $type->name;
            if (str_contains($restBase, '(') || $type->name === 'nav_menu_item') {
                continue;
            }
            $names[] = $type->name;
        }

        return $names;
    }

    public static function allows(string $postType): bool
    {
        return in_array($postType, self::names(), true);
    }

    /**
     * Registered statuses that are not internal (publish, draft, pending,
     * private, future and plugin statuses like them), plus trash and `any`.
     * Internal ones — auto-draft, inherit, the request-* statuses of privacy
     * requests — are refused.
     */
    public static function allowsStatus(string $status): bool
    {
        // WP_Query also reads a comma-separated list: check each entry.
        $allowed = [...get_post_stati(['internal' => false]), 'any', 'trash'];
        foreach (explode(',', $status) as $entry) {
            if (! in_array(trim($entry), $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
