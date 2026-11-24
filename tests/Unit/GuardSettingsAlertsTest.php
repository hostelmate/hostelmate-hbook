<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\Guard;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Messages;

final class GuardSettingsAlertsTest extends TestCase {

	public function test_guard_returns_first_argument_and_logs_no_message_text(): void {
		$wrapped = Guard::wrap(
			static function ( string $value ): string {
				throw new \RuntimeException( 'guest@example.com secret' );
			}
		);
		$this->assertSame( 'untouched', $wrapped( 'untouched' ) );
		$row = $GLOBALS['wpdb']->inserted[0]['row'];
		$this->assertSame( Messages::GUARD_CAUGHT, $row['code'] );
		$this->assertStringContainsString( 'RuntimeException @ GuardSettingsAlertsTest.php:', $row['detail'] );
		$this->assertStringNotContainsString( 'guest@example.com', $row['detail'] );
	}

	public function test_guard_survives_a_broken_log(): void {
		$GLOBALS['wpdb']->fail = true;
		$log                   = tempnam( sys_get_temp_dir(), 'hm' );
		$previous              = ini_set( 'error_log', $log );
		$wrapped               = Guard::wrap(
			static function (): void {
				throw new \LogicException( 'boom' );
			}
		);
		$this->assertNull( $wrapped() );
		ini_set( 'error_log', (string) $previous );
		$this->assertStringContainsString( 'guard_caught LogicException', (string) file_get_contents( $log ) );
		unlink( $log );
	}

	public function test_settings_fall_back_to_defaults_when_tampered(): void {
		\WpStub::$options[ Config::OPT_SETTINGS ] = array(
			'mode'            => 'hacked',
			'alert_email'     => "a@b.c\r\nBcc: evil@x.y",
			'payment_methods' => array( 'stripe' => array( 'x' ), 'paypal' => str_repeat( 'p', 101 ) ),
			'precheck'        => 'yes',
		);
		$this->assertSame( Settings::defaults(), Settings::all() );
		\WpStub::$options[ Config::OPT_SETTINGS ] = 'not an array';
		$this->assertSame( Settings::defaults(), Settings::all() );
	}

	public function test_settings_update_keeps_good_fields_and_reports_bad_ones(): void {
		$errors = Settings::update(
			array(
				'alert_email'     => 'not-an-email',
				'payment_methods' => array( 'stripe' => 'Stripe (website)', 'paypal' => "two\nlines" ),
				'precheck'        => '',
			)
		);
		$this->assertSame( array( Messages::SETTINGS_BAD_EMAIL, Messages::SETTINGS_BAD_METHOD ), $errors );
		$all = Settings::all();
		$this->assertSame( '', $all['alert_email'] );
		$this->assertSame( 'Stripe (website)', $all['payment_methods']['stripe'] );
		$this->assertSame( 'Credit Card', $all['payment_methods']['paypal'] );
		$this->assertFalse( $all['precheck'] );
		$this->assertSame( 'owner@example.com', Settings::alert_email() );
		$this->assertSame( 'Cash', Settings::payment_method( 'bank-transfer-plugin' ) );
	}

	public function test_mode_changes_only_to_known_modes(): void {
		Settings::set_mode( Config::MODE_LIVE );
		$this->assertSame( Config::MODE_LIVE, Settings::mode() );
		$this->expectException( \InvalidArgumentException::class );
		Settings::set_mode( 'everything' );
	}

	public function test_red_alert_emails_once_a_day_amber_never(): void {
		Alerts::open( Messages::GUARD_CAUGHT, Config::ALERT_LEVEL_RED, 'HBook #A1B2' );
		Alerts::open( Messages::GUARD_CAUGHT, Config::ALERT_LEVEL_RED, 'HBook #A1B2' );
		Alerts::open( Messages::DB_UPGRADED, Config::ALERT_LEVEL_AMBER );
		$this->assertCount( 1, \WpStub::$mails );
		$this->assertSame( 'owner@example.com', \WpStub::$mails[0]['to'] );
		$this->assertStringContainsString( 'HBook #A1B2', \WpStub::$mails[0]['body'] );
		$this->assertCount( 2, Alerts::list() );
		$this->assertSame( Config::ALERT_LEVEL_RED, Alerts::list()[0]['level'] );
	}

	public function test_alert_references_cannot_inject_mail_headers(): void {
		Alerts::open( Messages::GUARD_CAUGHT, Config::ALERT_LEVEL_RED, "x\r\nBcc: evil@example.com" );
		$this->assertStringNotContainsString( "\n", \WpStub::$mails[0]['subject'] );
		$this->assertStringNotContainsString( "\r", Alerts::list()[0]['ref'] );
	}

	public function test_daily_email_budget(): void {
		for ( $i = 0; $i < Config::ALERT_EMAILS_PER_DAY + 5; $i++ ) {
			Alerts::open( Messages::GUARD_CAUGHT, Config::ALERT_LEVEL_RED, 'ref ' . $i );
		}
		$this->assertCount( Config::ALERT_EMAILS_PER_DAY, \WpStub::$mails );
	}

	public function test_resolve(): void {
		Alerts::open( Messages::GUARD_CAUGHT, Config::ALERT_LEVEL_AMBER, 'a' );
		Alerts::open( Messages::GUARD_CAUGHT, Config::ALERT_LEVEL_AMBER, 'b' );
		Alerts::open( Messages::DB_UPGRADED, Config::ALERT_LEVEL_AMBER );
		Alerts::resolve( Messages::GUARD_CAUGHT, 'a' );
		$this->assertCount( 2, Alerts::list() );
		Alerts::resolve_code( Messages::GUARD_CAUGHT );
		$this->assertCount( 1, Alerts::list() );
	}

	public function test_tampered_alerts_option_is_ignored(): void {
		\WpStub::$options[ Config::OPT_ALERTS ] = array( 'x' => 'junk', 'y' => array( 'code' => 1 ) );
		$this->assertSame( array(), Alerts::list() );
	}
}
