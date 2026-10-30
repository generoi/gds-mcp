<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\MailSendAbility;
use WP_UnitTestCase;

class MailSendAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        reset_phpmailer_instance();
        // The test site's default, wordpress@localhost, is refused as invalid.
        add_filter('wp_mail_from', fn () => 'site@example.com');
    }

    /** @return list<string> */
    private static function addresses(int $count, string $prefix): array
    {
        return array_map(fn ($i) => "{$prefix}{$i}@example.com", range(1, $count));
    }

    public function test_the_daily_cap_counts_every_recipient(): void
    {
        $first = MailSendAbility::execute(['to' => self::addresses(4, 'to'), 'cc' => self::addresses(3, 'cc'), 'bcc' => self::addresses(3, 'bcc'), 'subject' => 'Hi', 'body' => 'Hello']);
        $this->assertSame(10, $first['remaining_today']);

        $second = MailSendAbility::execute(['to' => self::addresses(10, 'more'), 'subject' => 'Hi', 'body' => 'Hello']);
        $this->assertSame(0, $second['remaining_today']);

        $third = MailSendAbility::execute(['to' => ['last@example.com'], 'subject' => 'Hi', 'body' => 'Hello']);
        $this->assertWPError($third);
        $this->assertSame('daily_limit_reached', $third->get_error_code());
    }

    public function test_one_email_may_not_have_more_than_ten_recipients(): void
    {
        $result = MailSendAbility::execute(['to' => self::addresses(6, 'to'), 'bcc' => self::addresses(5, 'bcc'), 'subject' => 'Hi', 'body' => 'Hello']);

        $this->assertWPError($result);
        $this->assertSame('too_many_recipients', $result->get_error_code());
        $this->assertSame(20, MailSendAbility::execute(['to' => ['one@example.com'], 'subject' => 'Hi', 'body' => 'Hello'])['remaining_today'] + 1, 'A refused email uses none of the allowance.');
    }
}
