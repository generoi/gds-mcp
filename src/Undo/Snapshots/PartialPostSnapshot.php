<?php

namespace GeneroWP\MCP\Undo\Snapshots;

use GeneroWP\MCP\Undo\RestoreSnapshot;
use GeneroWP\MCP\Undo\Snapshot;
use WP_Post;

/**
 * Minimal capture for a partial (merge) restore: the post's current status
 * plus the prior values of ONLY the given meta keys. Used by bulk-update,
 * which may touch hundreds of posts — snapshotting full content + every
 * meta value per row would balloon the audit log, and a restore that
 * overwrote untouched meta would clobber concurrent edits.
 *
 * `meta_prev[$key] === null` means the key had no value when the snapshot
 * was taken; the restore deletes the key entirely.
 *
 * Built by {@see Snapshot::partialPost()}. Replayed by
 * {@see RestoreSnapshot::restorePostPartial()}.
 */
final class PartialPostSnapshot
{
    /**
     * @param  array<string, list<string>|null>  $metaPrev  Meta key → prior values; null = key was absent.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $status,
        public readonly array $metaPrev,
    ) {}

    /**
     * @param  array<int, string>  $metaKeys
     */
    public static function capture(int $id, array $metaKeys): ?self
    {
        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return null;
        }

        $metaPrev = [];
        foreach (array_unique($metaKeys) as $key) {
            $values = get_post_meta($id, (string) $key); // [] when absent
            $metaPrev[(string) $key] = $values !== []
                ? array_values(array_map('strval', $values))
                : null;
        }

        return new self(
            id: $id,
            status: (string) $post->post_status,
            metaPrev: $metaPrev,
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): ?self
    {
        $id = (int) ($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $metaPrev = [];
        if (is_array($raw['meta_prev'] ?? null)) {
            foreach ($raw['meta_prev'] as $key => $values) {
                $metaPrev[(string) $key] = $values === null
                    ? null
                    : array_values(array_map('strval', (array) $values));
            }
        }

        return new self(
            id: $id,
            status: (string) ($raw['status'] ?? ''),
            metaPrev: $metaPrev,
        );
    }

    /** @return array{id: int, status: string, meta_prev: array<string, list<string>|null>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'meta_prev' => $this->metaPrev,
        ];
    }
}
