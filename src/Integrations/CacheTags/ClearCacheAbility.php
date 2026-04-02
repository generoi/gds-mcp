<?php

namespace GeneroWP\MCP\Integrations\CacheTags;

use Genero\Sage\CacheTags\Actions\Site;
use Genero\Sage\CacheTags\CacheTags;
use GeneroWP\MCP\Abilities\HelpAbility;
use WP_Error;

/**
 * Cache clearing via sage-cachetags, which abstracts Kinsta, Fastly,
 * WP Super Cache, SiteGround, etc.
 *
 * When the Site action is enabled (multisite-aware), tags are prefixed
 * with site:N: (e.g. site:1:post:123) and a full-site purge uses
 * site:N tags instead of a global flush.
 */
final class ClearCacheAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/cache/clear', [
            'label' => 'Clear Cache',
            'description' => self::buildDescription(),
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'flush = clear all pages for the current site. tags = purge specific content by cache tags.',
                        'default' => 'flush',
                        'enum' => ['flush', 'tags'],
                    ],
                    'tags' => [
                        'type' => 'array',
                        'description' => self::buildTagsDescription(),
                        'items' => ['type' => 'string'],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'site_action' => ['type' => 'boolean', 'description' => 'Whether the Site action is active (tags are prefixed with site:N:).'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => true,
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
            return new WP_Error('insufficient_capability', 'You do not have permission to clear the cache.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $type = $input['type'] ?? 'flush';

        if (! function_exists('app') || ! app()->bound(CacheTags::class)) {
            wp_cache_flush();

            return [
                'success' => true,
                'message' => 'Object cache flushed. sage-cachetags not available for page cache purge.',
                'site_action' => false,
            ];
        }

        $cacheTags = app(CacheTags::class);
        $siteActionEnabled = self::isSiteActionEnabled();

        if ($type === 'tags') {
            $tags = $input['tags'] ?? [];
            if (empty($tags)) {
                return new WP_Error('missing_tags', 'At least one cache tag is required when using type=tags.');
            }

            $cacheTags->clear($tags);
            $result = $cacheTags->purgeQueued();

            return [
                'success' => $result,
                'message' => $result
                    ? sprintf('Purged %d cache tag(s): %s', count($tags), implode(', ', $tags))
                    : 'Cache tag purge failed.',
                'site_action' => $siteActionEnabled,
            ];
        }

        // Full flush -- sage-cachetags handles site IDs internally.
        $result = $cacheTags->flush();

        return [
            'success' => $result,
            'message' => $result ? 'Full cache flush completed.' : 'Cache flush failed.',
            'site_action' => $siteActionEnabled,
        ];
    }

    private static function isSiteActionEnabled(): bool
    {
        $actions = config('cachetags.action', []);

        return in_array(Site::class, $actions, true);
    }

    private static function buildDescription(): string
    {
        $desc = 'Clear the site cache via sage-cachetags (handles Kinsta, Fastly, etc.). '
            .'Can flush the entire site or purge specific content by cache tag.';

        if (self::isSiteActionEnabled()) {
            $desc .= ' Site action is ENABLED — tags are prefixed with site:{blog_id}: (e.g. site:1:post:123).';
        }

        return $desc;
    }

    private static function buildTagsDescription(): string
    {
        if (self::isSiteActionEnabled()) {
            $siteId = get_current_blog_id();

            return 'Cache tags to purge (for type=tags). '
                ."Site action is enabled — all tags must be prefixed with site:{$siteId}: "
                ."(e.g. site:{$siteId}:post:123, site:{$siteId}:archive:post, site:{$siteId}:term:45).";
        }

        return 'Cache tags to purge (for type=tags). '
            .'Tag formats: post:{id}, term:{id}, archive:{post_type}, taxonomy:{taxonomy}, user:{id}.';
    }
}
