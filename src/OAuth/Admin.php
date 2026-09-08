<?php

namespace GeneroWP\MCP\OAuth;

/**
 * "MCP connections" screen: which clients hold a token, and a way to take it
 * away. Everyone sees their own connections; user managers see all of them.
 */
final class Admin
{
    private const ACTION = 'gds_mcp_oauth_revoke';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_'.self::ACTION, [self::class, 'revoke']);
    }

    public static function menu(): void
    {
        add_users_page(
            __('MCP connections', 'gds-mcp'),
            __('MCP connections', 'gds-mcp'),
            'read',
            'gds-mcp-connections',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        // `edit_users`, not `list_users`: seeing every connection is only
        // useful if you can also revoke one, and on multisite a site
        // administrator has the latter without the former.
        $manageAll = current_user_can('edit_users');
        $users = $manageAll ? Grants::users() : [wp_get_current_user()];

        echo '<div class="wrap"><h1>'.esc_html__('MCP connections', 'gds-mcp').'</h1>';
        echo '<p>'.esc_html__(
            'Applications you have authorized to use this site as your WordPress account.',
            'gds-mcp'
        ).'</p>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a flag to show a notice.
        if (isset($_GET['revoked'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                .esc_html__('The connection was revoked. Its tokens no longer work.', 'gds-mcp')
                .'</p></div>';
        }

        echo '<table class="widefat striped"><thead><tr>'
            .'<th>'.esc_html__('Application', 'gds-mcp').'</th>'
            .($manageAll ? '<th>'.esc_html__('User', 'gds-mcp').'</th>' : '')
            .'<th>'.esc_html__('Authorized', 'gds-mcp').'</th>'
            .'<th>'.esc_html__('Last token issued', 'gds-mcp').'</th>'
            .'<th></th></tr></thead><tbody>';

        $rows = 0;
        foreach ($users as $user) {
            foreach (Grants::all($user->ID) as $grantId => $grant) {
                $rows++;
                echo '<tr>';
                echo '<td>'.esc_html($grant['client_name']);
                if (! Grants::isLive($grant)) {
                    echo ' <em>'.esc_html__('(expired)', 'gds-mcp').'</em>';
                }
                echo '<br><code>'.esc_html($grant['client_id']).'</code></td>';
                if ($manageAll) {
                    echo '<td>'.esc_html($user->user_login).'</td>';
                }
                echo '<td>'.esc_html(self::date($grant['created'])).'</td>';
                echo '<td>'.esc_html(self::date($grant['last_used'])).'</td>';
                echo '<td>'.self::revokeButton($user->ID, (string) $grantId).'</td>';
                echo '</tr>';
            }
        }

        if ($rows === 0) {
            echo '<tr><td colspan="'.($manageAll ? 5 : 4).'">'
                .esc_html__('No applications connected.', 'gds-mcp').'</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    public static function revoke(): void
    {
        $userId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
        $grantId = isset($_GET['grant']) ? sanitize_key(wp_unslash($_GET['grant'])) : '';

        check_admin_referer(self::ACTION.'_'.$userId.'_'.$grantId);

        if ($userId !== get_current_user_id() && ! current_user_can('edit_user', $userId)) {
            wp_die(esc_html__('You are not allowed to revoke this connection.', 'gds-mcp'));
        }

        Grants::revoke($userId, $grantId);

        wp_safe_redirect(admin_url('users.php?page=gds-mcp-connections&revoked=1'));
        exit;
    }

    private static function revokeButton(int $userId, string $grantId): string
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action='.self::ACTION.'&user='.$userId.'&grant='.$grantId),
            self::ACTION.'_'.$userId.'_'.$grantId
        );

        return '<a class="button button-small" href="'.esc_url($url).'">'
            .esc_html__('Revoke', 'gds-mcp').'</a>';
    }

    private static function date(int $timestamp): string
    {
        return wp_date(get_option('date_format').' '.get_option('time_format'), $timestamp);
    }
}
