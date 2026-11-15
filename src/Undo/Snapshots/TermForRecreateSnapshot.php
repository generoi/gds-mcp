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
 * Also what deleting the term does to others, so the undo can reverse it:
 * core re-parents its children, gives posts left without a term the default
 * one, and deletes the menu items linking to it (for a menu: every item in
 * it, and its theme locations are emptied).
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
     * @param  array{locations: array<string, int>, auto_add: bool}|null  $menu
     * @param  list<int>  $children  Direct child terms, which core moves up to this term's parent.
     * @param  list<array<string, mixed>>  $menuItems  {@see PostFieldsSnapshot} payloads of the menu items the delete removes.
     * @param  int  $defaultTerm  The term core gives posts the delete leaves with none (the default category), 0 if none.
     * @param  list<int>  $gainedDefault  The posts that get it.
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
        ?array $menu = null,
        public readonly array $children = [],
        public readonly array $menuItems = [],
        public readonly int $defaultTerm = 0,
        public readonly array $gainedDefault = [],
    ) {
        parent::__construct($termId, $taxonomy, $fields, $meta, $menu);
    }

    public static function capture(int $termId, string $taxonomy): ?self
    {
        global $wpdb;
        $term = get_term($termId, $taxonomy);
        if (! $term instanceof WP_Term) {
            return null;
        }

        $base = TermFieldsSnapshot::capture($termId, $taxonomy);
        if (! $base) {
            return null;
        }

        $objects = array_values(array_map('intval', (array) (get_objects_in_term($termId, $taxonomy) ?: [])));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $children = is_taxonomy_hierarchical($taxonomy)
            ? array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent = %d AND taxonomy = %s", $termId, $taxonomy)))
            : [];

        $itemIds = $taxonomy === 'nav_menu' ? $objects : array_map('intval', wp_get_associated_nav_menu_items($termId, 'taxonomy', $taxonomy));
        $menuItems = [];
        foreach ($itemIds as $itemId) {
            $item = PostFieldsSnapshot::capture($itemId);
            if ($item) {
                $menuItems[] = $item->toArray();
            }
        }

        // As wp_delete_term() decides it: the default category, or the
        // taxonomy's registered default term, for posts it was the only one of.
        $default = $taxonomy === 'category' ? (int) get_option('default_category') : (! empty(get_taxonomy($taxonomy)->default_term) ? (int) get_option('default_term_'.$taxonomy) : 0);
        $gainedDefault = [];
        if ($default && $default !== $termId) {
            foreach ($objects as $objectId) {
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
                    $objectId,
                    $taxonomy,
                ));
                if ($count === 1) {
                    $gainedDefault[] = $objectId;
                }
            }
        }
        // phpcs:enable

        return new self(
            termId: $base->termId,
            taxonomy: $base->taxonomy,
            fields: $base->fields,
            meta: $base->meta,
            termTaxonomyId: (int) $term->term_taxonomy_id,
            termGroup: (int) $term->term_group,
            objectIds: $objects,
            language: function_exists('pll_get_term_language') ? (string) pll_get_term_language($termId) : '',
            translations: function_exists('pll_get_term_translations') ? array_map('intval', pll_get_term_translations($termId)) : [],
            menu: $base->menu,
            children: $children,
            menuItems: $menuItems,
            defaultTerm: $gainedDefault ? $default : 0,
            gainedDefault: $gainedDefault,
        );
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): ?self
    {
        $base = TermFieldsSnapshot::fromArray($raw);
        if (! $base) {
            return null;
        }
        $ints = static fn (mixed $value): array => is_array($value) ? array_values(array_map('intval', $value)) : [];

        return new self(
            termId: $base->termId,
            taxonomy: $base->taxonomy,
            fields: $base->fields,
            meta: $base->meta,
            termTaxonomyId: (int) ($raw['term_taxonomy_id'] ?? 0),
            termGroup: (int) ($raw['term_group'] ?? 0),
            objectIds: $ints($raw['object_ids'] ?? null),
            language: (string) ($raw['language'] ?? ''),
            translations: is_array($raw['translations'] ?? null) ? array_map('intval', $raw['translations']) : [],
            menu: $base->menu,
            children: $ints($raw['children'] ?? null),
            menuItems: is_array($raw['menu_items'] ?? null) ? array_values(array_filter($raw['menu_items'], 'is_array')) : [],
            defaultTerm: (int) ($raw['default_term'] ?? 0),
            gainedDefault: $ints($raw['gained_default'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return parent::toArray() + [
            'term_taxonomy_id' => $this->termTaxonomyId,
            'term_group' => $this->termGroup,
            'object_ids' => $this->objectIds,
            'language' => $this->language,
            'translations' => $this->translations,
            'children' => $this->children,
            'menu_items' => $this->menuItems,
            'default_term' => $this->defaultTerm,
            'gained_default' => $this->gainedDefault,
        ];
    }
}
