<?php

namespace GeneroWP\MCP\Integrations\Yoast;

use WP_Error;
use WP_Post;
use WP_Term;
use WPSEO_Meta;
use WPSEO_Options;
use WPSEO_Taxonomy_Meta;
use Yoast\WP\SEO\Builders\Primary_Term_Builder;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher;

/**
 * Reads and writes Yoast SEO's per-object fields under one friendly schema.
 *
 * Posts store them as `_yoast_wpseo_*` post meta (protected, so the generic
 * REST/meta abilities can't reach them); terms store them in the
 * `wpseo_taxonomy_meta` option. Writes go through Yoast's own APIs so its
 * sanitising and validation apply. Post indexables are rebuilt by Yoast's
 * meta watcher at shutdown; term indexables only rebuild on `edited_term`,
 * so {@see self::rebuildTermIndexable()} does that by hand.
 */
final class SeoFields
{
    /**
     * Friendly field → [post meta key (without prefix), term meta key, type].
     * A null key means the field doesn't exist for that object type.
     *
     * @var array<string, array{0: string|null, 1: string|null, 2: string}>
     */
    public const FIELDS = [
        'title' => ['title', 'wpseo_title', 'string'],
        'description' => ['metadesc', 'wpseo_desc', 'string'],
        'focus_keyphrase' => ['focuskw', 'wpseo_focuskw', 'string'],
        'canonical' => ['canonical', 'wpseo_canonical', 'url'],
        'breadcrumb_title' => ['bctitle', 'wpseo_bctitle', 'string'],
        'robots_index' => ['meta-robots-noindex', 'wpseo_noindex', 'robots'],
        'cornerstone' => ['is_cornerstone', 'wpseo_is_cornerstone', 'bool'],
        'opengraph_title' => ['opengraph-title', 'wpseo_opengraph-title', 'string'],
        'opengraph_description' => ['opengraph-description', 'wpseo_opengraph-description', 'string'],
        'opengraph_image_id' => ['opengraph-image-id', 'wpseo_opengraph-image-id', 'image'],
        'twitter_title' => ['twitter-title', 'wpseo_twitter-title', 'string'],
        'twitter_description' => ['twitter-description', 'wpseo_twitter-description', 'string'],
        'twitter_image_id' => ['twitter-image-id', 'wpseo_twitter-image-id', 'image'],
        'primary_category' => ['primary_category', null, 'term'],
    ];

    /** Fields Yoast hides behind `wpseo_edit_advanced_metadata` when advanced meta is disabled. */
    public const ADVANCED = ['canonical', 'breadcrumb_title', 'robots_index'];

    /** Post `meta-robots-noindex` values ↔ friendly enum. */
    private const POST_ROBOTS = ['default' => '0', 'noindex' => '1', 'index' => '2'];

    /**
     * @return array<string, mixed>
     */
    public static function readPost(WP_Post $post): array
    {
        $fields = [];
        foreach (self::FIELDS as $name => [$key, , $type]) {
            $fields[$name] = self::fromStored($type, (string) WPSEO_Meta::get_value($key, $post->ID), 'post');
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readTerm(WP_Term $term): array
    {
        $stored = (array) WPSEO_Taxonomy_Meta::get_term_meta($term->term_id, $term->taxonomy);

        $fields = [];
        foreach (self::FIELDS as $name => [, $key, $type]) {
            if ($key === null) {
                continue;
            }
            $fields[$name] = self::fromStored($type, (string) ($stored[$key] ?? ''), 'term');
        }

        return $fields;
    }

    /**
     * The values Yoast actually outputs, with templates and fallbacks applied.
     *
     * @return array<string, mixed>
     */
    public static function resolved(string $type, int $id): array
    {
        if (! function_exists('YoastSEO')) {
            return [];
        }

        try {
            $meta = $type === 'post' ? YoastSEO()->meta->for_post($id) : YoastSEO()->meta->for_term($id);
        } catch (\Throwable) {
            return [];
        }

        if (! $meta) {
            return [];
        }

        $robots = (array) $meta->robots;

        return [
            'title' => (string) $meta->title,
            'description' => (string) $meta->description,
            'canonical' => (string) $meta->canonical,
            'robots' => implode(', ', array_filter($robots)),
            'opengraph_title' => (string) $meta->open_graph_title,
            'opengraph_description' => (string) $meta->open_graph_description,
            'opengraph_images' => array_values(array_map(
                static fn ($image) => (string) ($image['url'] ?? ''),
                (array) $meta->open_graph_images,
            )),
        ];
    }

    /**
     * Fields in `$input` that only users allowed to edit Yoast's advanced
     * settings may change.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function blockedAdvancedFields(array $input): array
    {
        $disabled = class_exists(WPSEO_Options::class) && WPSEO_Options::get('disableadvanced_meta');
        if (! $disabled || current_user_can('wpseo_edit_advanced_metadata')) {
            return [];
        }

        return array_values(array_intersect(self::ADVANCED, array_keys($input)));
    }

    /**
     * Validates `$input` and turns it into `[meta key => stored value]` for the
     * object type. An empty string means "clear the field".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>|WP_Error
     */
    public static function toStored(string $objectType, array $input, ?WP_Post $post = null): array|WP_Error
    {
        $stored = [];

        foreach ($input as $name => $value) {
            if (! isset(self::FIELDS[$name])) {
                return new WP_Error('invalid_field', "Unknown SEO field '{$name}'.");
            }

            [$postKey, $termKey, $type] = self::FIELDS[$name];
            $key = $objectType === 'post' ? $postKey : $termKey;
            if ($key === null) {
                return new WP_Error('invalid_field', "'{$name}' can't be set on a {$objectType}.");
            }

            $result = match ($type) {
                'string' => sanitize_text_field((string) $value),
                'url' => self::url($value),
                'bool' => $value ? '1' : '0',
                'robots' => self::robots($objectType, $value),
                'image' => self::image((int) $value),
                'term' => self::primaryCategory((int) $value, $post),
            };

            if (is_wp_error($result)) {
                return $result;
            }

            // Images are stored as both an attachment id and its URL.
            if ($type === 'image') {
                $urlKey = substr($key, 0, -3);
                $stored[$key] = $result === '' ? '' : $result;
                $stored[$urlKey] = $result === '' ? '' : (string) wp_get_attachment_url((int) $result);

                continue;
            }

            $stored[$key] = $result;
        }

        return $stored;
    }

    /**
     * @param  array<string, string>  $stored  Meta key (without prefix) → value.
     */
    public static function writePost(int $postId, array $stored): void
    {
        foreach ($stored as $key => $value) {
            if ($value === '') {
                WPSEO_Meta::delete($key, $postId);
            } else {
                WPSEO_Meta::set_value($key, $value, $postId);
            }
        }

        // The primary term also lives in Yoast's own table, built on save_post.
        if (array_key_exists('primary_category', $stored)) {
            self::rebuildPrimaryTerms($postId);
        }
    }

    public static function rebuildPrimaryTerms(int $postId): void
    {
        if (! function_exists('YoastSEO') || ! class_exists(Primary_Term_Builder::class)) {
            return;
        }

        try {
            YoastSEO()->classes->get(Primary_Term_Builder::class)->build($postId);
        } catch (\Throwable) {
            // Yoast rebuilds it on the next post save.
        }
    }

    /**
     * @param  array<string, string>  $stored  `wpseo_*` key → value.
     */
    public static function writeTerm(WP_Term $term, array $stored): void
    {
        $current = (array) WPSEO_Taxonomy_Meta::get_term_meta($term->term_id, $term->taxonomy);
        WPSEO_Taxonomy_Meta::set_values($term->term_id, $term->taxonomy, array_merge($current, $stored));
        self::rebuildTermIndexable($term->term_id);
    }

    /**
     * The term's raw entry in `wpseo_taxonomy_meta`, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public static function rawTermEntry(int $termId, string $taxonomy): ?array
    {
        $all = get_option('wpseo_taxonomy_meta', []);
        $entry = is_array($all) ? ($all[$taxonomy][$termId] ?? null) : null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Puts a term's raw `wpseo_taxonomy_meta` entry back as it was captured.
     *
     * @param  array<string, mixed>|null  $entry  Null removes the entry.
     */
    public static function restoreRawTermEntry(int $termId, string $taxonomy, ?array $entry): void
    {
        $all = get_option('wpseo_taxonomy_meta', []);
        $all = is_array($all) ? $all : [];

        if ($entry === null) {
            unset($all[$taxonomy][$termId]);
            if (isset($all[$taxonomy]) && $all[$taxonomy] === []) {
                unset($all[$taxonomy]);
            }
        } else {
            $all[$taxonomy][$termId] = $entry;
        }

        // The entry was already validated when Yoast saved it.
        $all['wpseo_already_validated'] = true;
        update_option('wpseo_taxonomy_meta', $all);

        self::rebuildTermIndexable($termId);
    }

    public static function rebuildTermIndexable(int $termId): void
    {
        if (! function_exists('YoastSEO') || ! class_exists(Indexable_Term_Watcher::class)) {
            return;
        }

        try {
            YoastSEO()->classes->get(Indexable_Term_Watcher::class)->build_indexable($termId);
        } catch (\Throwable) {
            // Yoast rebuilds the indexable on the next term save.
        }
    }

    private static function fromStored(string $type, string $value, string $objectType): mixed
    {
        return match ($type) {
            'bool' => $value === '1',
            'image', 'term' => $value === '' ? null : (int) $value,
            'robots' => $objectType === 'post'
                ? (array_search($value === '' ? '0' : $value, self::POST_ROBOTS, true) ?: 'default')
                : ($value === '' ? 'default' : $value),
            default => $value,
        };
    }

    private static function url(mixed $value): string|WP_Error
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_canonical', 'canonical must be an absolute URL.');
        }

        return esc_url_raw($value);
    }

    private static function robots(string $objectType, mixed $value): string|WP_Error
    {
        $value = (string) $value;
        if (! isset(self::POST_ROBOTS[$value])) {
            return new WP_Error('invalid_robots_index', 'robots_index must be default, index or noindex.');
        }

        return $objectType === 'post' ? self::POST_ROBOTS[$value] : $value;
    }

    private static function image(int $attachmentId): string|WP_Error
    {
        if ($attachmentId <= 0) {
            return '';
        }
        if (! wp_attachment_is_image($attachmentId)) {
            return new WP_Error('invalid_image', "Attachment {$attachmentId} is not an image.");
        }

        return (string) $attachmentId;
    }

    private static function primaryCategory(int $termId, ?WP_Post $post): string|WP_Error
    {
        if ($termId <= 0) {
            return '';
        }
        if (! $post || ! has_term($termId, 'category', $post)) {
            return new WP_Error('invalid_primary_category', "Category {$termId} is not assigned to this post.");
        }

        return (string) $termId;
    }
}
