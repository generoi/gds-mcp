<?php

namespace GeneroWP\MCP\Undo\Snapshots;

use GeneroWP\MCP\Undo\RestoreSnapshot;
use GeneroWP\MCP\Undo\Snapshot;

/**
 * Per-post translation membership captured before a relink — enough to
 * restore every post that was touched AND every sibling currently in those
 * groups (so siblings the relink would orphan are restored too).
 *
 * Built by {@see Snapshot::translationLinkBefore()}.
 * Replayed by
 * {@see RestoreSnapshot::restoreTranslationLink()}.
 */
final class TranslationLinkBeforeSnapshot
{
    /**
     * @param  list<TranslationLinkRow>  $before
     */
    public function __construct(
        public readonly array $before,
    ) {}

    /**
     * @param  list<int>  $postIds
     */
    public static function capture(array $postIds): self
    {
        if (! function_exists('pll_get_post_translations')) {
            return new self([]);
        }

        $rows = [];
        $seen = [];
        foreach ($postIds as $postId) {
            $group = pll_get_post_translations((int) $postId);
            $members = array_merge([(int) $postId], array_map('intval', array_values($group)));
            foreach ($members as $member) {
                if ($member <= 0 || isset($seen[$member])) {
                    continue;
                }
                $seen[$member] = true;
                $rows[] = new TranslationLinkRow(
                    id: $member,
                    lang: (string) (pll_get_post_language($member) ?: ''),
                    group: array_map('intval', pll_get_post_translations($member)),
                );
            }
        }

        return new self($rows);
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        $rows = [];
        if (is_array($raw['before'] ?? null)) {
            foreach ($raw['before'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = new TranslationLinkRow(
                    id: (int) ($row['id'] ?? 0),
                    lang: (string) ($row['lang'] ?? ''),
                    group: is_array($row['group'] ?? null)
                        ? array_map('intval', $row['group'])
                        : [],
                );
            }
        }

        return new self($rows);
    }

    /** @return array{before: list<array{id: int, lang: string, group: array<string, int>}>} */
    public function toArray(): array
    {
        return [
            'before' => array_map(fn (TranslationLinkRow $r) => $r->toArray(), $this->before),
        ];
    }
}
