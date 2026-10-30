<?php

namespace GeneroWP\MCP\Undo\Snapshots;

use GeneroWP\MCP\Undo\RestoreSnapshot;
use GeneroWP\MCP\Undo\Snapshot;
use WP_Term;

/**
 * Everything needed to recreate a deleted term under its ORIGINAL id and
 * re-attach it to the posts that had it. Captures the term_taxonomy_id so the
 * restore can re-insert with the same ids — that means existing references
 * (nav menus, ACF term fields, query blocks) keep working without rewrites.
 *
 * Extends {@see TermFieldsSnapshot} with the bits only a recreate needs.
 *
 * Built by {@see Snapshot::termForRecreate()}. Replayed by
 * {@see RestoreSnapshot::recreateTerm()}.
 */
final class TermForRecreateSnapshot extends TermFieldsSnapshot
{
    /**
     * @param  TermCoreFields  $fields
     * @param  array<string, list<string>>  $meta
     * @param  list<int>  $objectIds
     * @param  string  $language  Polylang language slug; Polylang drops it with the term.
     * @param  array<string, int>  $translations  Polylang translation group, language → term id.
     *
     * @phpstan-param  array{
     *     name: string,
     *     slug: string,
     *     description: string,
     *     parent: int,
     * }  $fields
     */
    public function __construct(
        int $termId,
        string $taxonomy,
        array $fields,
        array $meta,
        public readonly int $termTaxonomyId,
        public readonly int $termGroup,
        public readonly array $objectIds,
        public readonly string $language = '',
        public readonly array $translations = [],
    ) {
        parent::__construct($termId, $taxonomy, $fields, $meta);
    }

    public static function capture(int $termId, string $taxonomy): ?self
    {
        $term = get_term($termId, $taxonomy);
        if (! $term instanceof WP_Term) {
            return null;
        }

        $base = TermFieldsSnapshot::capture($termId, $taxonomy);
        if (! $base) {
            return null;
        }

        $objects = get_objects_in_term($termId, $taxonomy) ?: [];

        return new self(
            termId: $base->termId,
            taxonomy: $base->taxonomy,
            fields: $base->fields,
            meta: $base->meta,
            termTaxonomyId: (int) $term->term_taxonomy_id,
            termGroup: (int) $term->term_group,
            objectIds: array_values(array_map('intval', (array) $objects)),
            language: function_exists('pll_get_term_language') ? (string) pll_get_term_language($termId) : '',
            translations: function_exists('pll_get_term_translations') ? array_map('intval', pll_get_term_translations($termId)) : [],
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): ?self
    {
        $base = TermFieldsSnapshot::fromArray($raw);
        if (! $base) {
            return null;
        }
        $objectIds = is_array($raw['object_ids'] ?? null)
            ? array_values(array_map('intval', $raw['object_ids']))
            : [];

        return new self(
            termId: $base->termId,
            taxonomy: $base->taxonomy,
            fields: $base->fields,
            meta: $base->meta,
            termTaxonomyId: (int) ($raw['term_taxonomy_id'] ?? 0),
            termGroup: (int) ($raw['term_group'] ?? 0),
            objectIds: $objectIds,
            language: (string) ($raw['language'] ?? ''),
            translations: is_array($raw['translations'] ?? null) ? array_map('intval', $raw['translations']) : [],
        );
    }

    /** @return array{term_id: int, taxonomy: string, fields: array<string, mixed>, meta: array<string, list<string>>, term_taxonomy_id: int, term_group: int, object_ids: list<int>, language: string, translations: array<string, int>} */
    public function toArray(): array
    {
        return parent::toArray() + [
            'term_taxonomy_id' => $this->termTaxonomyId,
            'term_group' => $this->termGroup,
            'object_ids' => $this->objectIds,
            'language' => $this->language,
            'translations' => $this->translations,
        ];
    }
}
