<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class PatchBlockAbility
{
    private const OPERATION_FIELDS = [
        'action', 'block_name', 'occurrence', 'attrs', 'set_attrs',
        'remove_attrs', 'inner_html', 'inner_blocks', 'search_depth',
        'position', 'markup',
    ];

    private const OPERATION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'Operation type: "patch" (default), "insert", or "delete".',
                'enum' => ['patch', 'insert', 'delete'],
                'default' => 'patch',
            ],
            'block_name' => [
                'type' => 'string',
                'description' => 'Block name to find (e.g. "gds/card", "core/heading"). For insert: the reference block.',
            ],
            'occurrence' => [
                'type' => 'integer',
                'description' => 'Which occurrence to target (1-based). Default: 1. Use 0 for ALL (patch/delete only).',
                'default' => 1,
            ],
            'attrs' => [
                'type' => 'object',
                'description' => 'Patch: merge attributes (existing preserved, provided override).',
            ],
            'set_attrs' => [
                'type' => 'object',
                'description' => 'Patch: replace ALL attributes.',
            ],
            'remove_attrs' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Patch: attribute keys to remove.',
            ],
            'inner_html' => [
                'type' => 'string',
                'description' => 'Patch: replace innerHTML (leaf blocks only).',
            ],
            'inner_blocks' => [
                'type' => 'string',
                'description' => 'Patch: replace inner blocks with new block markup.',
            ],
            'position' => [
                'type' => 'string',
                'description' => 'Insert: where to insert relative to the target block. "before", "after" (default), "prepend" (first child), "append" (last child).',
                'enum' => ['before', 'after', 'prepend', 'append'],
                'default' => 'after',
            ],
            'markup' => [
                'type' => 'string',
                'description' => 'Insert: block markup to insert (parsed via parse_blocks).',
            ],
            'search_depth' => [
                'type' => 'integer',
                'description' => 'Max recursion depth (default: 10, 1 = top-level only).',
                'default' => 10,
            ],
        ],
        'required' => ['block_name'],
    ];

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/blocks-patch', [
            'label' => 'Patch Block',
            'description' => 'Modify, insert, or delete blocks within a post. Finds blocks by name (+ occurrence) and patches attributes/content, inserts new blocks before/after/inside, or deletes blocks. Supports batch operations.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => 'The post ID containing the block(s).',
                    ],
                    // Single operation fields.
                    ...array_map(fn ($p) => $p, self::OPERATION_SCHEMA['properties']),
                    // Batch operations.
                    'operations' => [
                        'type' => 'array',
                        'items' => self::OPERATION_SCHEMA,
                        'description' => 'Array of operations. All applied in one parse/serialize round-trip.',
                    ],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'id' => ['type' => 'integer'],
                    'results' => ['type' => 'array'],
                    'total_modified' => ['type' => 'integer'],
                    'content' => ['type' => 'string'],
                    'modified' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);

        $postId = $input['id'] ?? 0;
        if (! $postId) {
            return new WP_Error('missing_id', 'id is required.');
        }

        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        $operations = self::normalizeOperations($input);
        if (is_wp_error($operations)) {
            return $operations;
        }

        $blocks = parse_blocks($post->post_content);

        $results = [];
        $totalModified = 0;

        foreach ($operations as $op) {
            $action = $op['action'] ?? 'patch';
            $blockName = $op['block_name'];
            $occurrence = (int) ($op['occurrence'] ?? 1);
            $searchDepth = min((int) ($op['search_depth'] ?? 10), 50);

            $modifiedCount = 0;
            $error = match ($action) {
                'patch' => self::applyPatch($blocks, $blockName, $occurrence, $searchDepth, $op, $modifiedCount),
                'insert' => self::applyInsert($blocks, $blockName, $occurrence, $searchDepth, $op, $modifiedCount),
                'delete' => self::applyDelete($blocks, $blockName, $occurrence, $searchDepth, $modifiedCount),
                default => new WP_Error('invalid_action', "Unknown action: {$action}"),
            };

            $results[] = [
                'action' => $action,
                'block_name' => $blockName,
                'occurrence' => $occurrence,
                'modified_count' => $modifiedCount,
                'error' => $error ? $error->get_error_message() : null,
            ];

            $totalModified += $modifiedCount;
        }

        if ($totalModified === 0) {
            $allErrors = array_filter($results, fn ($r) => $r['error'] !== null);
            if (count($allErrors) === count($results)) {
                return new WP_Error('all_operations_failed', 'No blocks were modified.', ['results' => $results]);
            }
        }

        $newContent = serialize_blocks($blocks);

        $updateResult = wp_update_post([
            'ID' => $postId,
            'post_content' => $newContent,
        ], true);

        if (is_wp_error($updateResult)) {
            return $updateResult;
        }

        $updated = get_post($postId);

        return [
            'success' => true,
            'id' => $postId,
            'results' => $results,
            'total_modified' => $totalModified,
            'content' => $updated->post_content,
            'modified' => $updated->post_modified_gmt,
        ];
    }

    private static function normalizeOperations(array $input): array|WP_Error
    {
        $operations = [];

        if (! empty($input['operations']) && is_array($input['operations'])) {
            foreach ($input['operations'] as $i => $op) {
                $op = (array) $op;
                if (empty($op['block_name'])) {
                    return new WP_Error('missing_block_name', "operations[{$i}] is missing block_name.");
                }
                $action = $op['action'] ?? 'patch';
                if ($action === 'patch' && ! self::hasPatchFields($op)) {
                    return new WP_Error('no_patch', "operations[{$i}] has no patch fields.");
                }
                if ($action === 'insert' && empty($op['markup'])) {
                    return new WP_Error('missing_markup', "operations[{$i}] insert requires markup.");
                }
                $operations[] = $op;
            }
        }

        if (! empty($input['block_name'])) {
            $singleOp = array_intersect_key($input, array_flip(self::OPERATION_FIELDS));
            $action = $singleOp['action'] ?? 'patch';

            if ($action === 'patch' && ! self::hasPatchFields($singleOp) && empty($operations)) {
                return new WP_Error('no_patch', 'Patch requires at least one of: attrs, set_attrs, remove_attrs, inner_html, inner_blocks.');
            }
            if ($action === 'insert' && empty($singleOp['markup'])) {
                return new WP_Error('missing_markup', 'Insert requires markup.');
            }

            if ($action !== 'patch' || self::hasPatchFields($singleOp)) {
                $operations[] = $singleOp;
            }
        }

        if (empty($operations)) {
            return new WP_Error('no_operations', 'Provide block_name with action/patch fields, or an operations array.');
        }

        return $operations;
    }

    private static function hasPatchFields(array $op): bool
    {
        return isset($op['attrs']) || isset($op['set_attrs']) || isset($op['remove_attrs'])
            || isset($op['inner_html']) || isset($op['inner_blocks']);
    }

    // ── Patch ───────────────────────────────────────────────────────────────

    private static function applyPatch(
        array &$blocks,
        string $blockName,
        int $occurrence,
        int $searchDepth,
        array $op,
        int &$modifiedCount,
    ): ?WP_Error {
        $matchCount = 0;
        $error = null;

        self::walkBlocks($blocks, $blockName, $occurrence, $searchDepth, 0, $matchCount, $modifiedCount, $error, function (&$block) use ($op) {
            if (isset($op['attrs'])) {
                $block['attrs'] = array_merge($block['attrs'] ?? [], (array) $op['attrs']);
            }
            if (isset($op['set_attrs'])) {
                $block['attrs'] = (array) $op['set_attrs'];
            }
            if (isset($op['remove_attrs'])) {
                foreach ($op['remove_attrs'] as $key) {
                    unset($block['attrs'][$key]);
                }
            }
            if (isset($op['inner_html'])) {
                if (! empty($block['innerBlocks'])) {
                    return new WP_Error('has_inner_blocks', 'Cannot set inner_html on a block with inner blocks.');
                }
                $block['innerHTML'] = $op['inner_html'];
                $block['innerContent'] = [$op['inner_html']];
            }
            if (isset($op['inner_blocks'])) {
                $newInnerBlocks = self::parseMarkup($op['inner_blocks']);
                $block['innerBlocks'] = $newInnerBlocks;
                $block['innerHTML'] = '';
                $block['innerContent'] = array_fill(0, count($newInnerBlocks), null);
            }

            return true;
        });

        if ($modifiedCount === 0 && $error === null) {
            return new WP_Error('block_not_found', "No '{$blockName}' block found".($occurrence > 1 ? " (occurrence #{$occurrence})" : ''));
        }

        return $error;
    }

    // ── Insert ──────────────────────────────────────────────────────────────

    private static function applyInsert(
        array &$blocks,
        string $blockName,
        int $occurrence,
        int $searchDepth,
        array $op,
        int &$modifiedCount,
    ): ?WP_Error {
        $position = $op['position'] ?? 'after';
        $newBlocks = self::parseMarkup($op['markup'] ?? '');

        if (empty($newBlocks)) {
            return new WP_Error('empty_markup', 'Insert markup produced no blocks.');
        }

        $matchCount = 0;
        $error = null;

        if ($position === 'prepend' || $position === 'append') {
            // Insert inside the target block's innerBlocks
            self::walkBlocks($blocks, $blockName, $occurrence, $searchDepth, 0, $matchCount, $modifiedCount, $error, function (&$block) use ($newBlocks, $position) {
                if ($position === 'prepend') {
                    $block['innerBlocks'] = array_merge($newBlocks, $block['innerBlocks'] ?? []);
                } else {
                    $block['innerBlocks'] = array_merge($block['innerBlocks'] ?? [], $newBlocks);
                }
                // Update innerContent to match
                $block['innerHTML'] = '';
                $block['innerContent'] = array_fill(0, count($block['innerBlocks']), null);

                return true;
            });
        } else {
            // Insert before/after the target block as a sibling
            $inserted = self::insertSibling($blocks, $blockName, $occurrence, $searchDepth, 0, $matchCount, $newBlocks, $position);
            if ($inserted) {
                $modifiedCount = 1;
            }
        }

        if ($modifiedCount === 0 && $error === null) {
            return new WP_Error('block_not_found', "No '{$blockName}' block found for insert.");
        }

        return $error;
    }

    private static function insertSibling(
        array &$blocks,
        string $targetName,
        int $occurrence,
        int $maxDepth,
        int $currentDepth,
        int &$matchCount,
        array $newBlocks,
        string $position,
    ): bool {
        for ($i = 0; $i < count($blocks); $i++) {
            if (($blocks[$i]['blockName'] ?? null) === $targetName) {
                $matchCount++;
                if ($matchCount === $occurrence || $occurrence === 0) {
                    $insertAt = $position === 'before' ? $i : $i + 1;
                    array_splice($blocks, $insertAt, 0, $newBlocks);

                    return true;
                }
            }

            if (! empty($blocks[$i]['innerBlocks']) && $currentDepth < $maxDepth) {
                if (self::insertSibling($blocks[$i]['innerBlocks'], $targetName, $occurrence, $maxDepth, $currentDepth + 1, $matchCount, $newBlocks, $position)) {
                    // Update parent's innerContent
                    $blocks[$i]['innerHTML'] = '';
                    $blocks[$i]['innerContent'] = array_fill(0, count($blocks[$i]['innerBlocks']), null);

                    return true;
                }
            }
        }

        return false;
    }

    // ── Delete ──────────────────────────────────────────────────────────────

    private static function applyDelete(
        array &$blocks,
        string $blockName,
        int $occurrence,
        int $searchDepth,
        int &$modifiedCount,
    ): ?WP_Error {
        $matchCount = 0;
        self::deleteBlocks($blocks, $blockName, $occurrence, $searchDepth, 0, $matchCount, $modifiedCount);

        if ($modifiedCount === 0) {
            return new WP_Error('block_not_found', "No '{$blockName}' block found to delete.");
        }

        return null;
    }

    private static function deleteBlocks(
        array &$blocks,
        string $targetName,
        int $occurrence,
        int $maxDepth,
        int $currentDepth,
        int &$matchCount,
        int &$deletedCount,
    ): void {
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            // Recurse into inner blocks first (depth-first)
            if (! empty($blocks[$i]['innerBlocks']) && $currentDepth < $maxDepth) {
                self::deleteBlocks($blocks[$i]['innerBlocks'], $targetName, $occurrence, $maxDepth, $currentDepth + 1, $matchCount, $deletedCount);

                if ($occurrence > 0 && $deletedCount > 0) {
                    return;
                }

                // Update parent's innerContent after child deletion
                $blocks[$i]['innerHTML'] = '';
                $blocks[$i]['innerContent'] = array_fill(0, count($blocks[$i]['innerBlocks']), null);
            }

            if (($blocks[$i]['blockName'] ?? null) === $targetName) {
                $matchCount++;
                if ($occurrence === 0 || $matchCount === $occurrence) {
                    array_splice($blocks, $i, 1);
                    $deletedCount++;

                    if ($occurrence > 0) {
                        return;
                    }
                }
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function parseMarkup(string $markup): array
    {
        $blocks = parse_blocks($markup);

        return array_values(array_filter(
            $blocks,
            fn ($b) => $b['blockName'] !== null || trim($b['innerHTML'] ?? '') !== ''
        ));
    }

    private static function walkBlocks(
        array &$blocks,
        string $targetName,
        int $occurrence,
        int $maxDepth,
        int $currentDepth,
        int &$matchCount,
        int &$modifiedCount,
        ?WP_Error &$error,
        callable $callback,
    ): void {
        foreach ($blocks as &$block) {
            if (($block['blockName'] ?? null) === $targetName) {
                $matchCount++;

                if ($occurrence === 0 || $matchCount === $occurrence) {
                    $result = $callback($block);
                    if (is_wp_error($result)) {
                        $error = $result;

                        return;
                    }
                    $modifiedCount++;

                    if ($occurrence > 0 && $matchCount === $occurrence) {
                        return;
                    }
                }
            }

            if (! empty($block['innerBlocks']) && $currentDepth < $maxDepth) {
                self::walkBlocks(
                    $block['innerBlocks'],
                    $targetName,
                    $occurrence,
                    $maxDepth,
                    $currentDepth + 1,
                    $matchCount,
                    $modifiedCount,
                    $error,
                    $callback,
                );

                if ($error || ($occurrence > 0 && $modifiedCount > 0)) {
                    return;
                }
            }
        }
    }
}
