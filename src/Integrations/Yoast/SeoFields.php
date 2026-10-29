<?php

namespace GeneroWP\MCP\Integrations\Yoast;

use WP_Error;
use WP_Post;
use WP_Term;
use WPSEO_Capability_Utils;
use WPSEO_Meta;
use WPSEO_Options;
use WPSEO_Taxonomy_Meta;
use WPSEO_Utils;
use Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer;

/**
 * Reads and writes Yoast SEO's per-object fields under one friendly schema.
 *
 * Posts store them as `_yoast_wpseo_*` post meta (protected, so the generic
 * REST/meta abilities can't reach them); terms store them in the
 * `wpseo_taxonomy_meta` option. Writes go through Yoast's own APIs so its
 * sanitising and validation apply, then save the object the way wp-admin
 * does ({@see self::touchPost()}, {@see self::touchTerm()}) so indexables,
 * primary terms and page caches follow.
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
        if (! $disabled || WPSEO_Capability_Utils::current_user_can('wpseo_edit_advanced_metadata')) {
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
                'string' => self::text($value),
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

        self::touchPost($postId);
    }

    /**
     * Saves the post unchanged, like a wp-admin save: Yoast rebuilds its
     * indexable and primary terms right away (not at shutdown, so `resolved`
     * is current), and page caches purge on the save hooks.
     */
    public static function touchPost(int $postId): void
    {
        wp_update_post(['ID' => $postId]);
        self::clearMemoizedMeta();
    }

    /**
     * @param  array<string, string>  $stored  `wpseo_*` key → value.
     */
    public static function writeTerm(WP_Term $term, array $stored): void
    {
        $current = (array) WPSEO_Taxonomy_Meta::get_term_meta($term->term_id, $term->taxonomy);
        WPSEO_Taxonomy_Meta::set_values($term->term_id, $term->taxonomy, array_merge($current, $stored));
        self::touchTerm($term);
    }

    /**
     * Prior raw values of the given `wpseo_*` keys; null = key was absent.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function termValues(WP_Term $term, array $keys): array
    {
        $entry = self::rawTermEntry($term->term_id, $term->taxonomy) ?? [];

        $prev = [];
        foreach ($keys as $key) {
            $prev[$key] = $entry[$key] ?? null;
        }

        return $prev;
    }

    /**
     * Puts back only the given keys of a term's `wpseo_taxonomy_meta` entry,
     * leaving anything changed since untouched.
     *
     * @param  array<string, mixed>  $prev  Key → prior raw value; null removes the key.
     */
    public static function restoreTermValues(WP_Term $term, array $prev): void
    {
        $all = get_option('wpseo_taxonomy_meta', []);
        $all = is_array($all) ? $all : [];
        $entry = (array) ($all[$term->taxonomy][$term->term_id] ?? []);

        foreach ($prev as $key => $value) {
            if ($value === null) {
                unset($entry[$key]);
            } else {
                $entry[$key] = $value;
            }
        }

        if ($entry === []) {
            unset($all[$term->taxonomy][$term->term_id]);
            if (isset($all[$term->taxonomy]) && $all[$term->taxonomy] === []) {
                unset($all[$term->taxonomy]);
            }
        } else {
            $all[$term->taxonomy][$term->term_id] = $entry;
        }

        // Values were validated when Yoast first saved them. The flag is
        // stripped again by Yoast's sanitize_option filter.
        if (class_exists(WPSEO_Taxonomy_Meta::class)) {
            $all['wpseo_already_validated'] = true;
        }
        update_option('wpseo_taxonomy_meta', $all);

        self::touchTerm($term);
    }

    /**
     * Fires `edited_term` like a wp-admin save, so Yoast rebuilds the term's
     * indexable and page caches purge.
     */
    public static function touchTerm(WP_Term $term): void
    {
        do_action('edited_term', $term->term_id, $term->term_taxonomy_id, $term->taxonomy, []);
        self::clearMemoizedMeta();
    }

    /** Yoast memoizes resolved meta per request; drop it so reads see the write. */
    private static function clearMemoizedMeta(): void
    {
        if (! function_exists('YoastSEO') || ! class_exists(Meta_Tags_Context_Memoizer::class)) {
            return;
        }

        try {
            YoastSEO()->classes->get(Meta_Tags_Context_Memoizer::class)->clear();
        } catch (\Throwable) {
            // Only affects `resolved` within this request.
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function rawTermEntry(int $termId, string $taxonomy): ?array
    {
        $all = get_option('wpseo_taxonomy_meta', []);
        $entry = is_array($all) ? ($all[$taxonomy][$termId] ?? null) : null;

        return is_array($entry) ? $entry : null;
    }

    private static function text(mixed $value): string
    {
        $value = (string) $value;

        // Core's sanitize_text_field() eats %xx sequences, which mangles Yoast
        // variables (%%category%% → %tegory%%). Yoast's own version keeps them.
        return class_exists(WPSEO_Utils::class) ? WPSEO_Utils::sanitize_text_field($value) : sanitize_text_field($value);
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
        $url = esc_url_raw($value, ['http', 'https']);
        if ($url === '' || ! wp_parse_url($url, PHP_URL_HOST)) {
            return new WP_Error('invalid_canonical', 'canonical must be an absolute http(s) URL.');
        }

        return $url;
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
