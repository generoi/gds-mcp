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
     * @param  array{locations: array<string, int>, auto_add: bool}|null  $menu  For a nav menu: every theme location's menu and whether the menu adds new pages, which the menus endpoint also writes. Null otherwise and in older rows.
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
        public readonly ?array $menu = null,
    ) {}

    /**
     * @return array{locations: array<string, int>, auto_add: bool}|null
     */
    public static function captureMenu(int $termId, string $taxonomy): ?array
    {
        if ($taxonomy !== 'nav_menu') {
            return null;
        }
        $options = (array) get_option('nav_menu_options', []);

        return [
            'locations' => array_map('intval', (array) get_nav_menu_locations()),
            'auto_add' => in_array($termId, (array) ($options['auto_add'] ?? []), true),
        ];
    }

    /**
     * @return array{locations: array<string, int>, auto_add: bool}|null
     */
    protected static function menuFromArray(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return [
            'locations' => array_map('intval', (array) ($raw['locations'] ?? [])),
            'auto_add' => ! empty($raw['auto_add']),
        ];
    }

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
            menu: self::captureMenu($termId, $taxonomy),
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
            menu: self::menuFromArray($raw['menu'] ?? null),
        );
    }

    /** @return array{term_id: int, taxonomy: string, fields: array<string, mixed>, meta: array<string, list<string>>, menu?: array{locations: array<string, int>, auto_add: bool}} */
    public function toArray(): array
    {
        $out = [
            'term_id' => $this->termId,
            'taxonomy' => $this->taxonomy,
            'fields' => $this->fields,
            'meta' => $this->meta,
        ];
        if ($this->menu !== null) {
            $out['menu'] = $this->menu;
        }

        return $out;
    }
}
