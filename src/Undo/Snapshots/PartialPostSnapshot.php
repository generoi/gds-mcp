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
    /** `meta_format` of a row whose values are stored serialized. */
    private const SERIALIZED = 'serialized';

    /**
     * @param  array<string, list<string>|null>  $metaPrev  Meta key → prior values; null = key was absent.
     * @param  bool  $serialized  Whether the values are serialized, as stored; older rows hold them as plain strings.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $status,
        public readonly array $metaPrev,
        public readonly bool $serialized = false,
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
            // Serialized as stored, so arrays survive; the restore unserializes.
            $metaPrev[(string) $key] = $values !== []
                ? array_values(array_map(fn ($value) => (string) maybe_serialize($value), $values))
                : null;
        }

        return new self(
            id: $id,
            status: (string) $post->post_status,
            metaPrev: $metaPrev,
            serialized: true,
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): ?self
    {
        $id = (int) ($raw['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        // Rows from 0.8.1 and before hold the values as plain strings, under
        // `metaPrev` when the snapshot was nested in a bulk undo. They are
        // written back as they are: unserializing one could turn a plugin's
        // own serialized-looking string into an array or an object.
        $rawMeta = $raw['meta_prev'] ?? $raw['metaPrev'] ?? null;
        $metaPrev = [];
        if (is_array($rawMeta)) {
            foreach ($rawMeta as $key => $values) {
                $metaPrev[(string) $key] = $values === null
                    ? null
                    : array_values(array_map('strval', (array) $values));
            }
        }

        return new self(
            id: $id,
            status: (string) ($raw['status'] ?? ''),
            metaPrev: $metaPrev,
            serialized: ($raw['meta_format'] ?? '') === self::SERIALIZED,
        );
    }

    /** @return array{id: int, status: string, meta_prev: array<string, list<string>|null>, meta_format?: string} */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'status' => $this->status,
            'meta_prev' => $this->metaPrev,
        ];
        if ($this->serialized) {
            $out['meta_format'] = self::SERIALIZED;
        }

        return $out;
    }
}
