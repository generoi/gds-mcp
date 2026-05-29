<?php

namespace GeneroWP\MCP\Undo\Snapshots;

/**
 * One row in a {@see TranslationLinkBeforeSnapshot}: a post id, its language
 * code, and the full translation group it belonged to at capture time.
 *
 * The whole group is captured (not just the changed pair) so a restore can
 * detect siblings the relink would have orphaned and re-attach them.
 */
final class TranslationLinkRow
{
    /**
     * @param  array<string, int>  $group  Polylang group: lang code → post id.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $lang,
        public readonly array $group,
    ) {}

    /** @return array{id: int, lang: string, group: array<string, int>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lang' => $this->lang,
            'group' => $this->group,
        ];
    }
}
