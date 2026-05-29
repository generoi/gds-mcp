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
 *
 * The `$data` slot accepts either a snapshot value object (any class under
 * `Snapshots\` exposing `toArray()`) or a plain array — abilities that build
 * their own ad-hoc shapes (`restore-redirect`, `restore-feed`) still pass an
 * array; abilities that lean on {@see Snapshot} pass the object straight
 * through.
 */
trait Reversible
{
    /**
     * @param  array<string, mixed>  $result  The successful tool result to return.
     * @param  string  $kind  Restore-handler key — see {@see RestoreSnapshot}.
     * @param  array<string, mixed>|object  $data  Restore payload. Objects must expose `toArray(): array`.
     * @param  string  $label  Human description of what undoing this does.
     * @return array<string, mixed>
     */
    protected function reversible(array $result, string $kind, array|object $data, string $label): array
    {
        if (is_object($data)) {
            if (! method_exists($data, 'toArray')) {
                throw new \InvalidArgumentException(
                    'Reversible data object '.$data::class.' must expose toArray(): array',
                );
            }
            $payload = $data->toArray();
        } else {
            $payload = $data;
        }
        $result['_undo'] = ['kind' => $kind, 'data' => $payload, 'label' => $label];

        return $result;
    }
}
