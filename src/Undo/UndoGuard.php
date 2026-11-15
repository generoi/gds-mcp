<?php

namespace GeneroWP\MCP\Undo;

use GeneroWP\MCP\Integrations\Yoast\SeoFields;
use GeneroWP\MCP\Undo\Snapshots\PostFieldsSnapshot;
use GeneroWP\MCP\Undo\Snapshots\TermFieldsSnapshot;
use WP_Error;

/**
 * Refuses an undo that would overwrite changes made after the write.
 *
 * When a write is marked reversible, {@see self::seal()} records a
 * fingerprint of the state the write left behind, in the undo payload under
 * `fingerprint`. Before replaying it, {@see self::check()} fingerprints the object
 * again: if anyone (a person in wp-admin, another assistant action, a plugin)
 * changed it since, the undo is refused rather than silently reverting their
 * work too.
 *
 * The fingerprint covers what the restore writes, not the modified date:
 * undoing a later write puts that state back, so undoing this one in turn
 * still works, and a change within the same second still counts.
 *
 * Payloads without `fingerprint` (audit rows from older releases) are not
 * checked.
 */
final class UndoGuard
{
    /** Meta the restore leaves alone, or that cron consumes after a save. */
    private const VOLATILE_META = ['_encloseme', '_pingme'];

    /**
     * @param  array<string, mixed>  $data  The undo payload, after toArray().
     * @return array<string, mixed>
     */
    public static function seal(string $kind, array $data): array
    {
        if ($kind === 'bulk') {
            foreach ((array) ($data['items'] ?? []) as $i => $item) {
                if (is_array($item) && ! empty($item['kind'])) {
                    $data['items'][$i]['data'] = self::seal((string) $item['kind'], (array) ($item['data'] ?? []));
                }
            }

            return $data;
        }

        $fingerprint = self::fingerprint($kind, $data);
        if ($fingerprint !== null) {
            $data['fingerprint'] = $fingerprint;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function check(string $kind, array $data): ?WP_Error
    {
        if (! isset($data['fingerprint'])) {
            return null;
        }
        // Gone altogether: the restore says so itself.
        $now = self::fingerprint($kind, $data);
        if ($now === null || $now === $data['fingerprint']) {
            return null;
        }

        return new WP_Error('changed_since', self::describe($kind, $data).' was changed after this action, so undoing it would also throw away those later changes. Nothing was undone. Undo the later changes first, or make this change by hand.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fingerprint(string $kind, array $data): ?string
    {
        $state = match ($kind) {
            'restore-post' => self::postState((int) ($data['id'] ?? 0)),
            'restore-post-content' => ($post = get_post((int) ($data['id'] ?? 0))) ? $post->post_content : null,
            'restore-post-partial' => self::partialState($data),
            'restore-menu-order' => ($post = get_post((int) ($data['id'] ?? 0))) ? (int) $post->menu_order : null,
            'restore-term' => self::termState((int) ($data['term_id'] ?? 0), (string) ($data['taxonomy'] ?? '')),
            'restore-form' => self::formState((int) ($data['id'] ?? 0)),
            'restore-feed' => self::feedState((int) ($data['feed_id'] ?? 0)),
            'restore-term-seo' => ($term = get_term((int) ($data['term_id'] ?? 0), (string) ($data['taxonomy'] ?? ''))) instanceof \WP_Term
                ? SeoFields::termValues($term, array_keys((array) ($data['prev'] ?? [])))
                : null,
            'restore-redirect' => ($data['provider'] ?? '') === 'yoast' ? get_post_meta((int) ($data['post_id'] ?? 0), '_yoast_wpseo_redirect') : null,
            'restore-translation-link' => self::translationState($data),
            'restore-string' => self::stringState($data),
            default => null,
        };

        return $state === null ? null : md5((string) wp_json_encode($state));
    }

    /** @return array<string, mixed>|null */
    private static function postState(int $id): ?array
    {
        $snapshot = PostFieldsSnapshot::capture($id);
        if (! $snapshot) {
            return null;
        }
        $state = $snapshot->toArray();
        $state['meta'] = array_diff_key($state['meta'], array_flip([...RestoreSnapshot::BOOKKEEPING_META, ...self::VOLATILE_META]));
        ksort($state['meta']);

        return $state;
    }

    /**
     * The status and the meta keys a partial restore writes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function partialState(array $data): ?array
    {
        $post = get_post((int) ($data['id'] ?? 0));
        if (! $post) {
            return null;
        }
        $state = ($data['status'] ?? '') !== '' ? ['status' => $post->post_status] : [];
        foreach (array_keys((array) ($data['meta_prev'] ?? $data['metaPrev'] ?? [])) as $key) {
            $state['meta'][$key] = get_post_meta($post->ID, (string) $key);
        }

        return $state;
    }

    /** @return array<string, mixed>|null */
    private static function termState(int $termId, string $taxonomy): ?array
    {
        $snapshot = TermFieldsSnapshot::capture($termId, $taxonomy);
        if (! $snapshot) {
            return null;
        }
        $state = $snapshot->toArray();
        ksort($state['meta']);

        return $state;
    }

    /** @return array<string, mixed>|null */
    private static function formState(int $id): ?array
    {
        if (! class_exists('GFAPI') || ! $id) {
            return null;
        }
        $form = \GFAPI::get_form($id);

        return is_array($form) ? $form : null;
    }

    /** @return array<string, mixed>|null */
    private static function feedState(int $id): ?array
    {
        if (! class_exists('GFAPI') || ! $id) {
            return null;
        }
        $feed = \GFAPI::get_feed($id);

        return is_array($feed) ? $feed : null;
    }

    /**
     * The language and translation group of every post a relink touched.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>|null
     */
    private static function translationState(array $data): ?array
    {
        if (! function_exists('pll_get_post_translations')) {
            return null;
        }
        $state = [];
        foreach ((array) ($data['before'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id) {
                $group = pll_get_post_translations($id);
                ksort($group);
                $state[$id] = [pll_get_post_language($id), $group];
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringState(array $data): ?string
    {
        if (! class_exists('PLL_MO') || ! function_exists('PLL')) {
            return null;
        }
        $language = PLL()->model->get_language((string) ($data['lang'] ?? ''));
        if (! $language) {
            return null;
        }
        $mo = new \PLL_MO;
        $mo->import_from_db($language);

        return (string) $mo->translate_if_any((string) ($data['string'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function describe(string $kind, array $data): string
    {
        return match ($kind) {
            'restore-term', 'restore-term-seo' => sprintf('The term %d', (int) ($data['term_id'] ?? 0)),
            'restore-form' => sprintf('Form %d', (int) ($data['id'] ?? 0)),
            'restore-feed' => sprintf('Feed %d', (int) ($data['feed_id'] ?? 0)),
            'restore-translation-link' => 'The translation links of these posts',
            'restore-string' => sprintf('The "%s" translation', (string) ($data['string'] ?? '')),
            'restore-redirect' => sprintf('The redirect on post %d', (int) ($data['post_id'] ?? 0)),
            default => sprintf('"%s" (post %d)', get_the_title((int) ($data['id'] ?? 0)), (int) ($data['id'] ?? 0)),
        };
    }
}
