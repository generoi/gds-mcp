<?php

namespace GeneroWP\MCP\Undo\Snapshots;

use GeneroWP\MCP\Undo\RestoreSnapshot;
use GeneroWP\MCP\Undo\Snapshot;
use WP_Term;

/**
 * Term core fields + meta, captured for an undoable term update. The term id
 * stays stable across the round-trip (update doesn't delete + recreate), so
 * the snapshot only needs the writable fields.
 *
 * Built by {@see Snapshot::termFields()}. Replayed by
 * {@see RestoreSnapshot::restoreTerm()}.
 */
class TermFieldsSnapshot
{
    /**
     * @param  TermCoreFields  $fields
     * @param  array<string, list<string>>  $meta
     *
     * @phpstan-param  array{
     *     name: string,
     *     slug: string,
     *     description: string,
     *     parent: int,
     * }  $fields
     */
    public function __construct(
        public readonly int $termId,
        public readonly string $taxonomy,
        public readonly array $fields,
        public readonly array $meta,
    ) {}

    public static function capture(int $termId, string $taxonomy): ?self
    {
        $term = get_term($termId, $taxonomy);
        if (! $term instanceof WP_Term) {
            return null;
        }

        $meta = [];
        foreach (get_term_meta($termId) as $k => $v) {
            $meta[(string) $k] = array_values(array_map('strval', (array) $v));
        }

        return new self(
            termId: $termId,
            taxonomy: $taxonomy,
            fields: [
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'description' => (string) $term->description,
                'parent' => (int) $term->parent,
            ],
            meta: $meta,
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): ?self
    {
        $termId = (int) ($raw['term_id'] ?? 0);
        $taxonomy = (string) ($raw['taxonomy'] ?? '');
        if ($termId <= 0 || $taxonomy === '') {
            return null;
        }
        $fields = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];
        $meta = [];
        if (is_array($raw['meta'] ?? null)) {
            foreach ($raw['meta'] as $k => $v) {
                $meta[(string) $k] = array_values(array_map('strval', (array) $v));
            }
        }

        return new self(
            termId: $termId,
            taxonomy: $taxonomy,
            fields: [
                'name' => (string) ($fields['name'] ?? ''),
                'slug' => (string) ($fields['slug'] ?? ''),
                'description' => (string) ($fields['description'] ?? ''),
                'parent' => (int) ($fields['parent'] ?? 0),
            ],
            meta: $meta,
        );
    }

    /** @return array{term_id: int, taxonomy: string, fields: array<string, mixed>, meta: array<string, list<string>>} */
    public function toArray(): array
    {
        return [
            'term_id' => $this->termId,
            'taxonomy' => $this->taxonomy,
            'fields' => $this->fields,
            'meta' => $this->meta,
        ];
    }
}
