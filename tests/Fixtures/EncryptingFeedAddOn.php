<?php

namespace GeneroWP\MCP\Tests\Fixtures;

/**
 * A feed add-on with one setting Gravity Forms encrypts, like an API key.
 * Load only when Gravity Forms is active.
 */
class EncryptingFeedAddOn extends \GFFeedAddOn
{
    public const SLUG = 'gds-test-encrypting';

    protected $_slug = self::SLUG;

    protected $_title = 'GDS test add-on';

    protected $_short_title = 'GDS test';

    private static ?self $instance = null;

    public static function get_instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function feed_settings_fields()
    {
        return [[
            'fields' => [
                ['name' => 'feed_name', 'type' => 'text'],
                ['name' => 'api_key', 'type' => 'text', 'encrypt' => true],
            ],
        ]];
    }
}
