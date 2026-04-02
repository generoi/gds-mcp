<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\UploadMediaAbility;
use WP_UnitTestCase;

class UploadMediaAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_rejects_invalid_url(): void
    {
        $result = UploadMediaAbility::execute(['url' => 'not-a-url']);
        $this->assertWPError($result);
        $this->assertSame('invalid_url', $result->get_error_code());
    }

    public function test_execute_rejects_non_http_scheme(): void
    {
        $result = UploadMediaAbility::execute(['url' => 'file:///etc/passwd']);
        $this->assertWPError($result);
        $this->assertSame('invalid_scheme', $result->get_error_code());
    }

    public function test_execute_rejects_php_scheme(): void
    {
        $result = UploadMediaAbility::execute(['url' => 'php://filter/resource=/etc/passwd']);
        $this->assertWPError($result);
    }

    public function test_execute_rejects_ftp_scheme(): void
    {
        $result = UploadMediaAbility::execute(['url' => 'ftp://example.com/file.jpg']);
        $this->assertWPError($result);
        $this->assertSame('invalid_scheme', $result->get_error_code());
    }

    public function test_execute_rejects_data_scheme(): void
    {
        $result = UploadMediaAbility::execute(['url' => 'data:text/html,<script>alert(1)</script>']);
        $this->assertWPError($result);
    }

    public function test_execute_rejects_empty_url(): void
    {
        $result = UploadMediaAbility::execute(['url' => '']);
        $this->assertWPError($result);
        $this->assertSame('invalid_url', $result->get_error_code());
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = UploadMediaAbility::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = UploadMediaAbility::checkPermission();
        $this->assertWPError($result);
    }
}
