<?php

namespace GeneroWP\MCP\Undo\Snapshots;

use GeneroWP\MCP\Undo\RestoreSnapshot;
use GeneroWP\MCP\Undo\Snapshot;
use WP_Post;

/**
 * Full state of a post needed to restore an edit: core writable fields, all
 * meta values, and term relationships. Used to undo content/blocks updates,
 * bulk updates, revision restores, machine-translates — anything that
 * mutates a post in place.
 *
 * Built by {@see Snapshot::postFields()} from a {@see WP_Post}
 * plus its meta and terms. Replayed by
 * {@see RestoreSnapshot::restorePost()}.
 *
 * Stored on the gds-assistant audit log as JSON, so the only persistent form
 * is the {@see self::toArray()} payload. {@see self::fromArray()} validates the
 * shape on the way back in — an audit row written by an older release should
 * never crash the restore path; missing fields become empty defaults so the
 * caller can react with a clean error instead of a TypeError.
 */
final class PostFieldsSnapshot
{
    /**
     * @param  PostCoreFields  $post
     * @param  array<string, list<string>>  $meta  Raw `get_post_meta($id)` shape: key → list of stored values.
     * @param  array<string, list<int>>  $terms  Taxonomy slug → list of term ids.
     *
     * @phpstan-param  array{
     *     post_title: string,
     *     post_content: string,
     *     post_excerpt: string,
     *     post_status: string,
     *     post_name: string,
     *     post_parent: int,
     *     menu_order: int,
     * }  $post
     */
    public function __construct(
        public readonly int $id,
        public readonly array $post,
        public readonly array $meta,
        public readonly array $terms,
    ) {}

    /**
     * Capture a fresh snapshot from the current state of a post. Returns null
     * when the post has gone away (so the caller can skip snapshotting rather
     * than persist a misleading row).
     */
    public static function capture(int $id): ?self
    {
        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return null;
        }

        $meta = [];
        foreach (get_post_meta($id) as $key => $values) {
            $meta[(string) $key] = array_values(array_map('strval', (array) $values));
        }

        $terms = [];
        foreach (get_object_taxonomies(get_post_type($id) ?: 'post') as $tax) {
            $ids = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
            if (! is_wp_error($ids) && $ids) {
                $terms[$tax] = array_map('intval', $ids);
            }
        }

        return new self(
            id: $id,
            post: [
                'post_title' => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'post_status' => (string) $post->post_status,
                'post_name' => (string) $post->post_name,
                'post_parent' => (int) $post->post_parent,
                'menu_order' => (int) $post->menu_order,
            ],
            meta: $meta,
            terms: $terms,
        );
    }

    /**
     * Decode the persisted JSON payload back into a snapshot. Silently fills
     * missing pieces — older audit rows are still restorable as long as `id`
     * is present.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $id = (int) ($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = is_array($raw['post'] ?? null) ? $raw['post'] : [];

        return new self(
            id: $id,
            post: [
                'post_title' => (string) ($rawPost['post_title'] ?? ''),
                'post_content' => (string) ($rawPost['post_content'] ?? ''),
                'post_excerpt' => (string) ($rawPost['post_excerpt'] ?? ''),
                'post_status' => (string) ($rawPost['post_status'] ?? ''),
                'post_name' => (string) ($rawPost['post_name'] ?? ''),
                'post_parent' => (int) ($rawPost['post_parent'] ?? 0),
                'menu_order' => (int) ($rawPost['menu_order'] ?? 0),
            ],
            meta: self::normaliseMeta($raw['meta'] ?? null),
            terms: self::normaliseTerms($raw['terms'] ?? null),
        );
    }

    /** @return array{id: int, post: array<string, mixed>, meta: array<string, list<string>>, terms: array<string, list<int>>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'post' => $this->post,
            'meta' => $this->meta,
            'terms' => $this->terms,
        ];
    }

    /** @return array<string, list<string>> */
    private static function normaliseMeta(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = array_values(array_map('strval', (array) $v));
        }

        return $out;
    }

    /** @return array<string, list<int>> */
    private static function normaliseTerms(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $tax => $ids) {
            $out[(string) $tax] = array_values(array_map('intval', (array) $ids));
        }

        return $out;
    }
}
