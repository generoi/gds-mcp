<?php

namespace GeneroWP\MCP\Undo;

use GeneroWP\MCP\Undo\Snapshots\PartialPostSnapshot;
use GeneroWP\MCP\Undo\Snapshots\PostFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermForRecreateSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TranslationLinkBeforeSnapshot;

/**
 * Captures the "before" state of an object so a write can be undone.
 *
 * Mutating abilities call a capture helper before they change anything and
 * attach the result to their successful response under the `_undo` key (via
 * {@see Reversible::reversible()}). gds-assistant peels `_undo` off the result —
 * it never reaches the LLM — and stores it on the audit-log row. To undo, it
 * passes the snapshot back through the `gds-mcp/restore_snapshot` filter,
 * which {@see RestoreSnapshot} handles.
 *
 * Helpers here are thin factories over the concrete snapshot value classes in
 * `Snapshots\` — abilities mostly want a one-liner and don't care about the
 * class layout. Calls that need to inspect or massage the snapshot reach for
 * the class directly.
 *
 * Each helper returns the typed snapshot object (or `null` when the target
 * has already gone away — the ability uses that to skip attaching `_undo`).
 */
final class Snapshot
{
    public static function postFields(int $id): ?PostFieldsSnapshot
    {
        return PostFieldsSnapshot::capture($id);
    }

    /**
     * Minimal capture for a partial (merge) restore: the current status plus
     * the prior values of ONLY the given meta keys. Used by bulk-update so a
     * large batch never snapshots full content/meta and the restore won't
     * clobber meta it didn't change.
     *
     * @param  array<int, string>  $metaKeys
     */
    public static function partialPost(int $id, array $metaKeys): ?PartialPostSnapshot
    {
        return PartialPostSnapshot::capture($id, $metaKeys);
    }

    /**
     * Capture prior languages + full translation groups for every post a
     * relink touches.
     *
     * @param  list<int>  $postIds
     */
    public static function translationLinkBefore(array $postIds): TranslationLinkBeforeSnapshot
    {
        return TranslationLinkBeforeSnapshot::capture($postIds);
    }

    public static function termFields(int $termId, string $taxonomy): ?TermFieldsSnapshot
    {
        return TermFieldsSnapshot::capture($termId, $taxonomy);
    }

    public static function termForRecreate(int $termId, string $taxonomy): ?TermForRecreateSnapshot
    {
        return TermForRecreateSnapshot::capture($termId, $taxonomy);
    }
}
