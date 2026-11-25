<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\Fx;

/** The stored rate: agreeing, refreshing inside the sync, newer rates in steps, too old, a changed pair, stopping. */
final class FxStateTest extends TestCase {

	private const NOW = 1764000000; // 2025-11-24 16:00 UTC.

	/** HostelMate's list answers with these rates, refreshed at $at. */
	private static function list_answers( array $rates, int $at ): void {
		$rows = array();
		$id   = 1;
		foreach ( $rates as $code => $rate ) {
			$rows[] = array(
				'id'             => $id++,
				'base_currency'  => 'USD',
				'quote_currency' => $code,
				'rate'           => $rate,
				'fetched_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $at ),
			);
		}
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $rows ),
		);
	}

	private static function down(): void {
		\WpStub::$next_response = new \WP_Error( 'http_request_failed', 'timeout' );
	}

	/** @return list<string> Open alert codes. */
	private static function alerts(): array {
		return array_column( Alerts::list(), 'code' );
	}

	private function agreed( string $mxn = '20.00000000', int $at = self::NOW - 3600 ): void {
		self::list_answers( array( 'MXN' => $mxn, 'EUR' => '0.90000000' ), $at );
		$this->assertSame( Messages::FX_AGREED, Fx::agree( 'USD', 'MXN', 7, self::NOW ) );
		\WpStub::$requests = array();
	}

	public function test_same_currency_stores_nothing_and_calls_nothing(): void {
		$this->assertSame( Messages::FX_SAME, Fx::agree( 'USD', 'usd', 7, self::NOW ) );
		$this->assertArrayNotHasKey( Config::OPT_FX, \WpStub::$options );
		$this->assertSame( array(), \WpStub::$requests );
	}

	public function test_agreeing_stores_todays_pair(): void {
		$this->agreed();
		$stored = Fx::stored();
		$this->assertNotNull( $stored );
		$this->assertSame( 'USD', $stored['hm'] );
		$this->assertSame( 'MXN', $stored['web'] );
		$this->assertSame( 7, $stored['agreed_by'] );
		$this->assertSame( '1', $stored['in_use']['hm_rate'] );
		$this->assertSame( '20.00000000', $stored['in_use']['web_rate'] );
		$this->assertSame( self::NOW - 3600, $stored['in_use']['refreshed'] );
	}

	public function test_agreeing_fails_cleanly(): void {
		self::down();
		$this->assertSame( Messages::FX_UNREACHABLE, Fx::agree( 'USD', 'MXN', 7, self::NOW ) );
		self::list_answers( array( 'EUR' => '0.9' ), self::NOW );
		$this->assertSame( Messages::FX_NO_RATE, Fx::agree( 'USD', 'MXN', 7, self::NOW ) );
		self::list_answers( array( 'MXN' => '20' ), self::NOW - Config::FX_MAX_AGE - 1 );
		$this->assertSame( Messages::FX_TOO_OLD, Fx::agree( 'USD', 'MXN', 7, self::NOW ) );
		$this->assertArrayNotHasKey( Config::OPT_FX, \WpStub::$options );
	}

	public function test_neither_side_usd(): void {
		self::list_answers( array( 'MXN' => '20.00000000', 'EUR' => '0.80000000' ), self::NOW );
		$this->assertSame( Messages::FX_AGREED, Fx::agree( 'EUR', 'MXN', 7, self::NOW ) );
		$pair = Fx::for_bookings( 'EUR', 'MXN', self::NOW );
		$this->assertNotNull( $pair );
		$this->assertSame( '25.000000', Fx::shown_rate( $pair ) ); // 1 EUR = 20 ÷ 0.8 MXN.
	}

	public function test_not_agreed_stops_as_without_conversion(): void {
		$r = Fx::for_sync( 'USD', 'MXN', self::NOW );
		$this->assertSame( Messages::SYNC_CURRENCY, $r['code'] );
		$this->assertNull( $r['pair'] );
		$this->assertSame( array(), \WpStub::$requests );
		$this->assertNull( Fx::for_bookings( 'USD', 'MXN', self::NOW ) );
	}

	public function test_fresh_list_is_not_fetched_again(): void {
		$this->agreed();
		$r = Fx::for_sync( 'USD', 'MXN', self::NOW + 600 );
		$this->assertSame( '', $r['code'] );
		$this->assertSame( '20.00000000', $r['pair']['web_rate'] );
		$this->assertNull( $r['next'] );
		$this->assertSame( array(), \WpStub::$requests );
	}

	public function test_a_day_later_it_fetches_once_and_retries_hourly(): void {
		$this->agreed();
		$later = self::NOW + Config::FX_REFRESH_AFTER;
		self::down();
		$r = Fx::for_sync( 'USD', 'MXN', $later );
		$this->assertSame( '', $r['code'], 'the stored rate is still used' );
		$this->assertCount( 1, \WpStub::$requests );
		Fx::for_sync( 'USD', 'MXN', $later + 600 );
		$this->assertCount( 1, \WpStub::$requests, 'not again within the hour' );
		self::list_answers( array( 'MXN' => '20.10000000' ), $later );
		Fx::for_sync( 'USD', 'MXN', $later + 3601 );
		$this->assertCount( 2, \WpStub::$requests );
		$this->assertSame( $later, Fx::stored()['newest']['refreshed'] );
	}

	public function test_too_old_stops_and_recovers(): void {
		$this->agreed();
		$late = self::NOW - 3600 + Config::FX_MAX_AGE + 1;
		self::down();
		$r = Fx::for_sync( 'USD', 'MXN', $late );
		$this->assertSame( Messages::FX_TOO_OLD, $r['code'] );
		$this->assertContains( Messages::FX_TOO_OLD, self::alerts() );
		$this->assertNull( Fx::for_bookings( 'USD', 'MXN', $late ) );
		self::list_answers( array( 'MXN' => '20.05000000' ), $late );
		$r = Fx::for_sync( 'USD', 'MXN', $late + 3601 );
		$this->assertSame( '', $r['code'] );
		$this->assertNotContains( Messages::FX_TOO_OLD, self::alerts() );
	}

	public function test_a_list_dated_in_the_future_is_refused(): void {
		self::list_answers( array( 'MXN' => '20' ), self::NOW + 2 * DAY_IN_SECONDS );
		$this->assertSame( Messages::FX_UNREACHABLE, Fx::agree( 'USD', 'MXN', 7, self::NOW ) );
	}

	public function test_newer_rates_in_steps(): void {
		$this->agreed();
		$day = self::NOW + Config::FX_REFRESH_AFTER;
		// +0.5%: ignored.
		self::list_answers( array( 'MXN' => '20.10000000' ), $day );
		$this->assertNull( Fx::for_sync( 'USD', 'MXN', $day )['next'] );
		// +5%: used, as its own step.
		self::list_answers( array( 'MXN' => '21.00000000' ), $day * 1 + Config::FX_REFRESH_AFTER );
		$r = Fx::for_sync( 'USD', 'MXN', $day + Config::FX_REFRESH_AFTER + 1 );
		$this->assertSame( '21.00000000', $r['next']['web_rate'] );
		$this->assertSame( '20.00000000', $r['pair']['web_rate'], 'prices still use the rate in use until the next step' );
		Fx::use_rate( $r['next'], $day + Config::FX_REFRESH_AFTER + 1 );
		$this->assertSame( '21.00000000', Fx::for_bookings( 'USD', 'MXN', $day + Config::FX_REFRESH_AFTER + 1 )['web_rate'] );
	}

	public function test_a_big_move_waits_for_the_owner(): void {
		$this->agreed();
		$day = self::NOW + Config::FX_REFRESH_AFTER;
		self::list_answers( array( 'MXN' => '23.00000000' ), $day ); // +15%.
		$r = Fx::for_sync( 'USD', 'MXN', $day );
		$this->assertNull( $r['next'] );
		$this->assertSame( '20.00000000', $r['pair']['web_rate'] );
		$this->assertContains( Messages::FX_HELD, self::alerts() );
		$this->assertSame( '23.00000000', Fx::held()['web_rate'] );

		// Keep the old one: the alert closes and stays closed for this rate...
		Fx::keep_rate();
		$this->assertNull( Fx::held() );
		Fx::for_sync( 'USD', 'MXN', $day + 600 );
		$this->assertNotContains( Messages::FX_HELD, self::alerts() );

		// ...but an even newer rate that is still far off asks again.
		self::list_answers( array( 'MXN' => '23.10000000' ), $day + Config::FX_REFRESH_AFTER );
		Fx::for_sync( 'USD', 'MXN', $day + Config::FX_REFRESH_AFTER + 1 );
		$this->assertContains( Messages::FX_HELD, self::alerts() );

		// Use it.
		Fx::use_rate( Fx::held(), $day + Config::FX_REFRESH_AFTER + 2 );
		$this->assertNull( Fx::held() );
		$this->assertNotContains( Messages::FX_HELD, self::alerts() );
		$this->assertSame( '23.10000000', Fx::stored()['in_use']['web_rate'] );
	}

	public function test_a_changed_pair_stops(): void {
		$this->agreed();
		$r = Fx::for_sync( 'EUR', 'MXN', self::NOW );
		$this->assertSame( Messages::FX_PAIR_CHANGED, $r['code'] );
		$this->assertContains( Messages::FX_PAIR_CHANGED, self::alerts() );
		$this->assertNull( Fx::for_bookings( 'EUR', 'MXN', self::NOW ) );
		$this->assertNull( Fx::for_bookings( 'USD', 'EUR', self::NOW ) );
		// Agreeing to the new pair replaces the old one and closes the alert.
		self::list_answers( array( 'MXN' => '20', 'EUR' => '0.9' ), self::NOW );
		$this->assertSame( Messages::FX_AGREED, Fx::agree( 'EUR', 'MXN', 7, self::NOW ) );
		$this->assertNotContains( Messages::FX_PAIR_CHANGED, self::alerts() );
		$this->assertSame( '', Fx::for_sync( 'EUR', 'MXN', self::NOW )['code'] );
	}

	public function test_stop_removes_everything(): void {
		$this->agreed();
		Alerts::open( Messages::FX_HELD, Config::ALERT_LEVEL_RED );
		Fx::stop();
		$this->assertArrayNotHasKey( Config::OPT_FX, \WpStub::$options );
		$this->assertNotContains( Messages::FX_HELD, self::alerts() );
		$this->assertSame( Messages::SYNC_CURRENCY, Fx::for_sync( 'USD', 'MXN', self::NOW )['code'] );
	}

	public function test_a_damaged_option_counts_as_not_agreed(): void {
		\WpStub::$options[ Config::OPT_FX ] = array( 'hm' => 'USD', 'web' => 'MXN', 'in_use' => array( 'hm_rate' => '0' ) );
		$this->assertNull( Fx::stored() );
		$this->assertSame( Messages::SYNC_CURRENCY, Fx::for_sync( 'USD', 'MXN', self::NOW )['code'] );
	}
}
