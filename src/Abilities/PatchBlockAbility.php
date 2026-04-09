<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class PatchBlockAbility
{
    use PolylangAware;

    private const OPERATION_FIELDS = ['block_name', 'occurrence', 'attrs', 'set_attrs', 'remove_attrs', 'inner_html', 'inner_blocks', 'search_depth'];

    private const OPERATION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'block_name' => [
                'type' => 'string',
                'description' => 'Block name to find (e.g. "gds/card", "core/heading").',
            ],
            'occurrence' => [
                'type' => 'integer',
                'description' => 'Which occurrence to patch (1-based). Default: 1. Use 0 to patch ALL occurrences.',
                'default' => 1,
            ],
            'attrs' => [
                'type' => 'object',
                'description' => 'Attributes to merge into the block (existing keys preserved, provided keys override).',
            ],
            'set_attrs' => [
                'type' => 'object',
                'description' => 'Replace ALL block attributes with these (not merged).',
            ],
            'remove_attrs' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Attribute keys to remove.',
            ],
            'inner_html' => [
                'type' => 'string',
                'description' => 'Replace innerHTML (leaf blocks without inner blocks only).',
            ],
            'inner_blocks' => [
                'type' => 'string',
                'description' => 'Replace inner blocks with new block markup (parsed via parse_blocks).',
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
            'description' => 'Update specific blocks within a post without replacing the full post_content. Finds blocks by name (+ occurrence) and patches attributes and/or inner content. Supports single or batch operations — one parse/serialize round-trip per call.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID containing the block(s) to patch.',
                    ],
                    // Single operation fields (convenience — same as operations[0]).
                    'block_name' => self::OPERATION_SCHEMA['properties']['block_name'],
                    'occurrence' => self::OPERATION_SCHEMA['properties']['occurrence'],
                    'attrs' => self::OPERATION_SCHEMA['properties']['attrs'],
                    'set_attrs' => self::OPERATION_SCHEMA['properties']['set_attrs'],
                    'remove_attrs' => self::OPERATION_SCHEMA['properties']['remove_attrs'],
                    'inner_html' => self::OPERATION_SCHEMA['properties']['inner_html'],
                    'inner_blocks' => self::OPERATION_SCHEMA['properties']['inner_blocks'],
                    'search_depth' => self::OPERATION_SCHEMA['properties']['search_depth'],
                    // Batch operations.
                    'operations' => [
                        'type' => 'array',
                        'items' => self::OPERATION_SCHEMA,
                        'description' => 'Array of patch operations. Each needs block_name + at least one patch field. All applied in one parse/serialize round-trip.',
                    ],
                ],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'post_id' => ['type' => 'integer'],
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'block_name' => ['type' => 'string'],
                                'occurrence' => ['type' => 'integer'],
                                'patched_count' => ['type' => 'integer'],
                                'error' => ['type' => ['string', 'null']],
                            ],
                        ],
                        'description' => 'Per-operation results.',
                    ],
                    'total_patched' => ['type' => 'integer'],
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
        $input = is_array($input) ? $input : [];

        $postId = $input['post_id'] ?? 0;
        if (! $postId) {
            return new WP_Error('missing_post_id', 'post_id is required.');
        }

        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }

        // Normalize operations: support both single (top-level fields) and batch (operations array).
        $operations = self::normalizeOperations($input);
        if (is_wp_error($operations)) {
            return $operations;
        }

        // Parse blocks once.
        $blocks = parse_blocks($post->post_content);

        // Apply each operation.
        $results = [];
        $totalPatched = 0;

        foreach ($operations as $op) {
            $blockName = $op['block_name'];
            $occurrence = (int) ($op['occurrence'] ?? 1);
            $searchDepth = min((int) ($op['search_depth'] ?? 10), 50);

            $patchedCount = 0;
            $error = self::applyOperation($blocks, $blockName, $occurrence, $searchDepth, $op, $patchedCount);

            $results[] = [
                'block_name' => $blockName,
                'occurrence' => $occurrence,
                'patched_count' => $patchedCount,
                'error' => $error ? $error->get_error_message() : null,
            ];

            $totalPatched += $patchedCount;
        }

        if ($totalPatched === 0) {
            // Check if all operations errored.
            $allErrors = array_filter($results, fn ($r) => $r['error'] !== null);
            if (count($allErrors) === count($results)) {
                return new WP_Error('all_operations_failed', 'No blocks were patched.', ['results' => $results]);
            }
        }

        // Serialize and save once.
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
            'post_id' => $postId,
            'results' => $results,
            'total_patched' => $totalPatched,
            'content' => $updated->post_content,
            'modified' => $updated->post_modified_gmt,
        ];
    }

    /**
     * Normalize input into an array of operations.
     *
     * @return array|WP_Error
     */
    private static function normalizeOperations(array $input): array|WP_Error
    {
        $operations = [];

        // Batch: operations array provided.
        if (! empty($input['operations']) && is_array($input['operations'])) {
            foreach ($input['operations'] as $i => $op) {
                if (empty($op['block_name'])) {
                    return new WP_Error('missing_block_name', "operations[{$i}] is missing block_name.");
                }
                if (! self::hasPatchFields($op)) {
                    return new WP_Error('no_patch', "operations[{$i}] has no patch fields (attrs, set_attrs, remove_attrs, inner_html, or inner_blocks).");
                }
                $operations[] = $op;
            }
        }

        // Single: top-level block_name provided.
        if (! empty($input['block_name'])) {
            $singleOp = array_intersect_key($input, array_flip(self::OPERATION_FIELDS));
            if (! self::hasPatchFields($singleOp)) {
                if (empty($operations)) {
                    return new WP_Error('no_patch', 'At least one patch field (attrs, set_attrs, remove_attrs, inner_html, or inner_blocks) must be provided.');
                }
            } else {
                $operations[] = $singleOp;
            }
        }

        if (empty($operations)) {
            return new WP_Error('no_operations', 'Provide block_name with patch fields, or an operations array.');
        }

        return $operations;
    }

    private static function hasPatchFields(array $op): bool
    {
        return isset($op['attrs']) || isset($op['set_attrs']) || isset($op['remove_attrs'])
            || isset($op['inner_html']) || isset($op['inner_blocks']);
    }

    /**
     * Apply a single patch operation to the blocks tree.
     */
    private static function applyOperation(
        array &$blocks,
        string $blockName,
        int $occurrence,
        int $searchDepth,
        array $op,
        int &$patchedCount,
    ): ?WP_Error {
        $matchCount = 0;
        $error = null;

        self::walkBlocks($blocks, $blockName, $occurrence, $searchDepth, 0, $matchCount, $patchedCount, $error, function (&$block) use ($op) {
            if (isset($op['attrs'])) {
                $block['attrs'] = array_merge($block['attrs'] ?? [], $op['attrs']);
            }

            if (isset($op['set_attrs'])) {
                $block['attrs'] = $op['set_attrs'];
            }

            if (isset($op['remove_attrs'])) {
                foreach ($op['remove_attrs'] as $key) {
                    unset($block['attrs'][$key]);
                }
            }

            if (isset($op['inner_html'])) {
                if (! empty($block['innerBlocks'])) {
                    return new WP_Error('has_inner_blocks', 'Cannot set inner_html on a block with inner blocks. Use inner_blocks instead.');
                }
                $block['innerHTML'] = $op['inner_html'];
                $block['innerContent'] = [$op['inner_html']];
            }

            if (isset($op['inner_blocks'])) {
                $newInnerBlocks = parse_blocks($op['inner_blocks']);
                $newInnerBlocks = array_values(array_filter(
                    $newInnerBlocks,
                    fn ($b) => $b['blockName'] !== null || trim($b['innerHTML'] ?? '') !== ''
                ));

                $block['innerBlocks'] = $newInnerBlocks;
                $block['innerHTML'] = '';
                $block['innerContent'] = array_fill(0, count($newInnerBlocks), null);
            }

            return true;
        });

        if ($patchedCount === 0 && $error === null) {
            return new WP_Error('block_not_found', "No '{$blockName}' block found" . ($occurrence > 1 ? " (occurrence #{$occurrence})" : ''));
        }

        return $error;
    }

    /**
     * Recursively walk blocks and apply a callback to matching blocks.
     */
    private static function walkBlocks(
        array &$blocks,
        string $targetName,
        int $occurrence,
        int $maxDepth,
        int $currentDepth,
        int &$matchCount,
        int &$patchedCount,
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
                    $patchedCount++;

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
                    $patchedCount,
                    $error,
                    $callback,
                );

                if ($error || ($occurrence > 0 && $patchedCount > 0)) {
                    return;
                }
            }
        }
    }
}
