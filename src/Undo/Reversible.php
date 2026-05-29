<?php

namespace GeneroWP\MCP\Undo;

/**
 * Marks a mutating ability's result as undoable.
 *
 * Capture the "before" state where it's naturally available (an update has
 * usually already read the current object) and hand it to {@see reversible()}.
 * gds-assistant peels the `_undo` envelope off before the result reaches the
 * LLM and stores it on the audit-log row; an undo replays it through the
 * `gds-mcp/restore_snapshot` filter (see {@see RestoreSnapshot}).
 *
 * Use {@see Snapshot} for the common capture shapes (post fields, terms, …).
 */
trait Reversible
{
    /**
     * @param  array<string, mixed>  $result  The successful tool result to return.
     * @param  string  $kind  Restore-handler key — see {@see RestoreSnapshot}.
     * @param  array<string, mixed>  $data  Everything needed to restore the prior state.
     * @param  string  $label  Human description of what undoing this does.
     * @return array<string, mixed>
     */
    protected function reversible(array $result, string $kind, array $data, string $label): array
    {
        $result['_undo'] = ['kind' => $kind, 'data' => $data, 'label' => $label];

        return $result;
    }
}
