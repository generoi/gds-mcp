<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\ResolvesPostId;
use GeneroWP\MCP\Undo\Reversible;
use GeneroWP\MCP\Undo\Snapshot;
use GeneroWP\MCP\Undo\Snapshots\PostFieldsSnapshot;
use WP_Error;

/**
 * Literal find-and-replace across the text content of a post's blocks, in one
 * safe, counted pass.
 *
 * This exists because gds/blocks-patch can only swap a block's whole innerHTML
 * or attributes — to change a word that appears in several places an LLM has to
 * locate and rewrite each block by hand, which is where it goes wrong (e.g.
 * occurrence:0 broadcasting the same text everywhere, or a substring matching
 * inside unrelated words). This tool does the replacement directly:
 *
 *   - literal match (no regex from the caller), with optional whole_word and
 *     case_sensitive toggles — whole_word treats any Unicode letter/digit as a
 *     word char, so "ilman" won't match inside "ilmanvaihto" or "Vilman";
 *   - only touches visible text — text nodes in innerHTML, never inside tags
 *     (so URLs, classes and attributes are left alone unless include_attrs);
 *   - dry_run returns the match count + context samples without writing;
 *   - expect_count aborts (without writing) if the real count differs, so a
 *     "replace exactly 4" can't silently become 14.
 */
final class ReplaceContentAbility
{
    use ResolvesPostId;
    use Reversible;

    private const SAMPLE_CAP = 12;

    /** Cap on posts a single multi-post run will snapshot for undo. */
    private const UNDO_MAX_POSTS = 50;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/content-replace', [
            'label' => 'Replace Content Text',
            'description' => 'Find-and-replace exact text across all blocks of a post in one safe pass. Use this instead of gds/blocks-patch when changing a word/phrase that appears in more than one place. Matches literal text (not regex). Always dry_run first to confirm the count, or pass expect_count to abort if the number of matches is not what you expect. Only visible text is changed (text between tags); URLs, CSS classes and other attributes are left untouched unless include_attrs is set. To find a target id, call gds/content-list first. Templates / template parts can be addressed by their composite REST id ("{theme}//{slug}", e.g. "gds//footer").',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => ['integer', 'string'],
                        'description' => 'A single post ID, or a composite template id "{theme}//{slug}" (e.g. "gds//footer", "gds//footer___sv"). Provide this OR ids.',
                    ],
                    'ids' => [
                        'type' => 'array',
                        'items' => ['type' => ['integer', 'string']],
                        'description' => 'Replace across MULTIPLE posts in one call (each item a post ID or composite template id). Use with gds/content-list to sweep a word/email/phrase across many pages. replaced_count is the combined total; dry_run / expect_count apply to that total so you can verify before writing; undo reverts every changed post.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'The exact text to find (literal, not a regular expression).',
                    ],
                    'replace' => [
                        'type' => 'string',
                        'description' => 'The replacement text.',
                    ],
                    'whole_word' => [
                        'type' => 'boolean',
                        'description' => 'Only match when "search" stands as a whole word (Unicode-aware). Prevents matching inside longer words. Default: false.',
                        'default' => false,
                    ],
                    'case_sensitive' => [
                        'type' => 'boolean',
                        'description' => 'Match case exactly. Default: true.',
                        'default' => true,
                    ],
                    'include_attrs' => [
                        'type' => 'boolean',
                        'description' => 'Also replace inside block attribute values (needed for custom blocks that store text in attributes, e.g. list/card items). Riskier — may touch non-visible attribute strings — so always dry_run first. Default: false.',
                        'default' => false,
                    ],
                    'dry_run' => [
                        'type' => 'boolean',
                        'description' => 'Count matches and return context samples WITHOUT changing anything. Default: false.',
                        'default' => false,
                    ],
                    'expect_count' => [
                        'type' => 'integer',
                        'description' => 'If set, abort without writing when the actual match count differs from this number. Use it to make a replacement atomic and safe.',
                    ],
                ],
                'required' => ['search', 'replace'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'id' => ['type' => 'integer'],
                    'search' => ['type' => 'string'],
                    'replace' => ['type' => 'string'],
                    'replaced_count' => ['type' => 'integer'],
                    'dry_run' => ['type' => 'boolean'],
                    'samples' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'posts' => ['type' => 'array'],
                    'updated_posts' => ['type' => 'integer'],
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
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);

        // `ids` present => multi-post mode (combined count, per-post undo).
        if (array_key_exists('ids', $input) && is_array($input['ids'])) {
            return $this->executeMulti($input);
        }

        return $this->executeSingle($input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|WP_Error
     */
    private function executeSingle(array $input): array|WP_Error
    {
        $postId = self::resolvePostId($input['id'] ?? null);
        if (is_wp_error($postId)) {
            return $postId;
        }
        if (! $postId) {
            return new WP_Error('missing_id', 'id is required.');
        }

        $o = self::options($input);
        if ($o['search'] === '') {
            return new WP_Error('missing_search', 'search must be a non-empty string.');
        }

        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Post not found.');
        }
        if (! current_user_can('edit_post', $postId)) {
            return new WP_Error('forbidden', 'You do not have permission to edit this post.', ['status' => 403]);
        }

        $plan = self::planPost($post, $o);

        if ($error = self::expectCountError($input, $plan['count'], ['samples' => $plan['samples']])) {
            return $error;
        }

        $result = [
            'success' => true,
            'id' => $postId,
            'search' => $o['search'],
            'replace' => $o['replace'],
            'replaced_count' => $plan['count'],
            'dry_run' => $o['dryRun'],
            'samples' => $plan['samples'],
        ];

        if ($o['dryRun'] || $plan['count'] === 0) {
            return $result;
        }

        $write = self::writePost($postId, $plan['content']);
        if (is_wp_error($write)) {
            return $write;
        }

        $result['content'] = $write['updated']->post_content;
        $result['modified'] = $write['updated']->post_modified_gmt;

        if ($write['before']) {
            $result = $this->reversible(
                $result,
                'restore-post',
                $write['before'],
                "Revert replacing \"{$o['search']}\" with \"{$o['replace']}\" in \"{$post->post_title}\"",
            );
        }

        return $result;
    }

    /**
     * Replace across multiple posts in one call. Plans every post first (no
     * writes), so expect_count is checked against the COMBINED total and the
     * run is all-or-nothing with respect to that guard. Each changed post is
     * snapshotted and reverted together via a single `bulk` undo.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|WP_Error
     */
    private function executeMulti(array $input): array|WP_Error
    {
        $o = self::options($input);
        if ($o['search'] === '') {
            return new WP_Error('missing_search', 'search must be a non-empty string.');
        }

        $rawIds = [];
        if (isset($input['id']) && $input['id'] !== '') {
            $rawIds[] = $input['id'];
        }
        foreach ($input['ids'] as $rawId) {
            $rawIds[] = $rawId;
        }
        if (empty($rawIds)) {
            return new WP_Error('missing_id', 'Provide id or a non-empty ids array.');
        }

        // ── Plan pass: count + render new content per post, write nothing.
        $plans = [];
        $perPost = [];
        $samples = [];
        $total = 0;
        $seen = [];

        foreach ($rawIds as $rawId) {
            $postId = self::resolvePostId($rawId);
            if (is_wp_error($postId)) {
                $perPost[] = ['id' => (string) $rawId, 'error' => $postId->get_error_message()];

                continue;
            }
            if (! $postId || isset($seen[$postId])) {
                continue;
            }
            $seen[$postId] = true;

            $post = get_post($postId);
            if (! $post) {
                $perPost[] = ['id' => $postId, 'error' => 'Post not found.'];

                continue;
            }
            if (! current_user_can('edit_post', $postId)) {
                $perPost[] = ['id' => $postId, 'title' => $post->post_title, 'error' => 'forbidden'];

                continue;
            }

            $plan = self::planPost($post, $o);
            $total += $plan['count'];
            self::mergeSamples($samples, $plan['samples'], "#{$postId}");
            $plans[$postId] = $plan;
            $perPost[] = ['id' => $postId, 'title' => $post->post_title, 'matched' => $plan['count']];
        }

        // ── Combined expect_count guard — abort before any write.
        if ($error = self::expectCountError($input, $total, ['samples' => $samples, 'posts' => $perPost])) {
            return $error;
        }

        $result = [
            'success' => true,
            'search' => $o['search'],
            'replace' => $o['replace'],
            'replaced_count' => $total,
            'dry_run' => $o['dryRun'],
            'posts' => $perPost,
            'samples' => $samples,
        ];

        if ($o['dryRun'] || $total === 0) {
            return $result;
        }

        // ── Apply pass: snapshot + write each post that had matches.
        $undoItems = [];
        $written = 0;

        foreach ($plans as $postId => $plan) {
            if ($plan['count'] === 0) {
                continue;
            }

            $write = self::writePost($postId, $plan['content']);
            if (is_wp_error($write)) {
                foreach ($perPost as &$row) {
                    if ($row['id'] === $postId) {
                        $row['error'] = 'update failed: '.$write->get_error_message();
                        unset($row['matched']);
                    }
                }
                unset($row);

                continue;
            }

            if ($write['before']) {
                $undoItems[] = ['kind' => 'restore-post', 'data' => $write['before']];
            }
            $written++;
        }

        $result['posts'] = $perPost;
        $result['updated_posts'] = $written;

        if ($undoItems) {
            if (count($undoItems) > self::UNDO_MAX_POSTS) {
                $result['undo_skipped'] = sprintf(
                    'Undo not recorded: %d posts changed exceeds the %d-post limit for storing a reversible snapshot.',
                    count($undoItems),
                    self::UNDO_MAX_POSTS,
                );
            } else {
                $result = $this->reversible(
                    $result,
                    'bulk',
                    ['items' => $undoItems],
                    sprintf('Revert replacing "%s" with "%s" across %d post(s)', $o['search'], $o['replace'], $written),
                );
            }
        }

        return $result;
    }

    /**
     * Normalise the matching options out of the raw input.
     *
     * @param  array<string, mixed>  $input
     * @return array{search: string, replace: string, wholeWord: bool, caseSensitive: bool, includeAttrs: bool, dryRun: bool}
     */
    private static function options(array $input): array
    {
        return [
            'search' => (string) ($input['search'] ?? ''),
            'replace' => (string) ($input['replace'] ?? ''),
            'wholeWord' => (bool) ($input['whole_word'] ?? false),
            'caseSensitive' => (bool) ($input['case_sensitive'] ?? true),
            'includeAttrs' => (bool) ($input['include_attrs'] ?? false),
            'dryRun' => (bool) ($input['dry_run'] ?? false),
        ];
    }

    /**
     * Plan a replacement for one post: count matches, gather context samples,
     * and render the new content. Writes nothing.
     *
     * @param  array{search: string, replace: string, wholeWord: bool, caseSensitive: bool, includeAttrs: bool, dryRun: bool}  $o
     * @return array{count: int, samples: list<string>, content: string}
     */
    private static function planPost(\WP_Post $post, array $o): array
    {
        $ctx = self::buildContext($o['search'], $o['replace'], $o['wholeWord'], $o['caseSensitive'], $o['includeAttrs']);
        $blocks = parse_blocks($post->post_content);
        self::walk($blocks, $ctx);

        return [
            'count' => $ctx['count'],
            'samples' => $ctx['samples'],
            'content' => $ctx['count'] > 0 ? serialize_blocks($blocks) : '',
        ];
    }

    /**
     * Snapshot then write a post's content. wp_update_post() runs its data
     * through wp_unslash(), so content with backslashes (code blocks, paths,
     * escapes) is corrupted unless slashed first.
     *
     * @return array{before: PostFieldsSnapshot|null, updated: \WP_Post}|WP_Error
     */
    private static function writePost(int $postId, string $content): array|WP_Error
    {
        $before = Snapshot::postFields($postId);

        $updateResult = wp_update_post(['ID' => $postId, 'post_content' => wp_slash($content)], true);
        if (is_wp_error($updateResult)) {
            return $updateResult;
        }

        $updated = get_post($postId);
        if (! $updated) {
            return new WP_Error('post_not_found', 'Post vanished during update.');
        }

        return ['before' => $before, 'updated' => $updated];
    }

    /**
     * The expect_count guard: returns an error (so the caller writes nothing)
     * when an expectation was given and the real count differs from it.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $extra  Extra data to attach (samples, posts).
     */
    private static function expectCountError(array $input, int $actual, array $extra = []): ?WP_Error
    {
        if (! array_key_exists('expect_count', $input) || $input['expect_count'] === null) {
            return null;
        }

        $expected = (int) $input['expect_count'];
        if ($actual === $expected) {
            return null;
        }

        return new WP_Error(
            'unexpected_count',
            "Found {$actual} match(es) but expected {$expected} — nothing was changed. "
            .'Re-run with dry_run to inspect the matches.',
            ['expected' => $expected, 'actual' => $actual] + $extra,
        );
    }

    /**
     * Append samples from one post into the global (capped) list, tagging each
     * with the post reference so a multi-post preview is readable.
     *
     * @param  list<string>  $into
     * @param  list<string>  $from
     */
    private static function mergeSamples(array &$into, array $from, ?string $prefix): void
    {
        foreach ($from as $sample) {
            if (count($into) >= self::SAMPLE_CAP) {
                return;
            }
            $into[] = $prefix !== null ? $prefix.' '.$sample : $sample;
        }
    }

    /**
     * Build the matching context: compiled patterns + mutable counters.
     *
     * @return array{pattern: string, ctxPattern: string, replace: string, includeAttrs: bool, count: int, samples: list<string>}
     */
    private static function buildContext(
        string $search,
        string $replace,
        bool $wholeWord,
        bool $caseSensitive,
        bool $includeAttrs,
    ): array {
        $quoted = preg_quote($search, '/');
        $core = $wholeWord
            ? '(?<![\p{L}\p{N}_])(?:'.$quoted.')(?![\p{L}\p{N}_])'
            : '(?:'.$quoted.')';
        $mods = 'u'.($caseSensitive ? '' : 'i');

        return [
            'pattern' => '/'.$core.'/'.$mods,
            // Capture up to 30 chars of context either side of the match.
            'ctxPattern' => '/(.{0,30})('.$core.')(.{0,30})/s'.$mods,
            'replace' => $replace,
            'includeAttrs' => $includeAttrs,
            'count' => 0,
            'samples' => [],
        ];
    }

    /**
     * Recurse the parsed block tree, replacing text in innerContent chunks
     * (the source of truth for serialize_blocks) and, when enabled, attrs.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array{pattern: string, ctxPattern: string, replace: string, includeAttrs: bool, count: int, samples: list<string>}  $ctx
     */
    private static function walk(array &$blocks, array &$ctx): void
    {
        foreach ($blocks as &$block) {
            if (! empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $i => $chunk) {
                    if (is_string($chunk) && $chunk !== '') {
                        $block['innerContent'][$i] = self::replaceInTextNodes($chunk, $ctx);
                    }
                }
                // serialize_blocks() reads innerContent, not innerHTML — no
                // need to keep innerHTML in sync; $blocks is serialized and
                // discarded immediately after this pass.
            } elseif (isset($block['innerHTML']) && $block['innerHTML'] !== '') {
                // Freeform / classic content has innerHTML but no chunked innerContent.
                $new = self::replaceInTextNodes($block['innerHTML'], $ctx);
                $block['innerHTML'] = $new;
                $block['innerContent'] = [$new];
            }

            if ($ctx['includeAttrs'] && ! empty($block['attrs']) && is_array($block['attrs'])) {
                $block['attrs'] = self::replaceInValue($block['attrs'], $ctx);
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::walk($block['innerBlocks'], $ctx);
            }
        }
    }

    /**
     * Replace inside an HTML string, but only in text nodes — never inside tags
     * (so href/src/class values are never touched).
     *
     * @param  array{pattern: string, ctxPattern: string, replace: string, includeAttrs: bool, count: int, samples: list<string>}  $ctx
     */
    private static function replaceInTextNodes(string $html, array &$ctx): string
    {
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        foreach ($parts as $idx => $part) {
            // Odd indices are the captured tags; even indices are text.
            if ($idx % 2 === 1 || $part === '') {
                continue;
            }
            $parts[$idx] = self::countingReplace($part, $ctx);
        }

        return implode('', $parts);
    }

    /**
     * Replace inside an attribute value of any shape (string / nested array).
     *
     * @param  array{pattern: string, ctxPattern: string, replace: string, includeAttrs: bool, count: int, samples: list<string>}  $ctx
     */
    private static function replaceInValue(mixed $value, array &$ctx): mixed
    {
        if (is_string($value)) {
            return self::countingReplace($value, $ctx);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::replaceInValue($v, $ctx);
            }
        }

        return $value;
    }

    /**
     * Literal replace on a raw string: bumps the counter, collects context
     * samples up to the cap, returns the new string.
     *
     * @param  array{pattern: string, ctxPattern: string, replace: string, includeAttrs: bool, count: int, samples: list<string>}  $ctx
     */
    private static function countingReplace(string $text, array &$ctx): string
    {
        if (count($ctx['samples']) < self::SAMPLE_CAP
            && preg_match_all($ctx['ctxPattern'], $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (count($ctx['samples']) >= self::SAMPLE_CAP) {
                    break;
                }
                $ctx['samples'][] = self::formatSnippet($m[1], $m[2], $m[3]);
            }
        }

        $replace = $ctx['replace'];

        return preg_replace_callback($ctx['pattern'], function () use (&$ctx, $replace) {
            $ctx['count']++;

            return $replace;
        }, $text);
    }

    private static function formatSnippet(string $before, string $match, string $after): string
    {
        $before = trim((string) preg_replace('/\s+/u', ' ', $before));
        $after = trim((string) preg_replace('/\s+/u', ' ', $after));

        return '…'.$before.'«'.$match.'»'.$after.'…';
    }
}
