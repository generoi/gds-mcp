<?php

namespace WP_Syntex\Polylang_Pro\Integrations\ACF;

/**
 * Stand-in for Polylang Pro's ACF integration when Polylang Pro is not
 * installed: like the real one, a static on_duplicate_term() that copies ACF
 * term fields by their translation settings.
 */
class Dispatcher
{
    /** @var list<array{int, int, string}> */
    public static array $calls = [];

    public static function on_duplicate_term($from, $to, $lang): void
    {
        self::$calls[] = [(int) $from, (int) $to, (string) $lang];
    }
}
