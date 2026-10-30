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
 * Calls from a remote MCP client get no `_undo` — see {@see McpRequest}.
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
        if (McpRequest::isActive()) {
            return $result;
        }

        $result['_undo'] = ['kind' => $kind, 'data' => self::undoPayload($data), 'label' => $label];

        return $result;
    }

    /**
     * The payload as gds-assistant stores it, JSON: snapshot objects nested
     * anywhere (a bulk undo's items) go through toArray() too, or their
     * property names would replace the keys fromArray() reads.
     */
    private static function undoPayload(mixed $value): mixed
    {
        if (is_object($value)) {
            if (! method_exists($value, 'toArray')) {
                throw new \InvalidArgumentException(
                    'Reversible data object '.$value::class.' must expose toArray(): array',
                );
            }
            $value = $value->toArray();
        }

        return is_array($value) ? array_map(fn ($item) => self::undoPayload($item), $value) : $value;
    }
}
