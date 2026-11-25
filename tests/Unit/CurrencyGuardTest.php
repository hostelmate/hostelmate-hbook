<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\BookingRunner;
use HostelMate\HBook\Sync\Fx;
use HostelMate\HBook\Sync\SyncRunner;

/** Money is only exchanged while both sides use the same currency (prices and website bookings). */
final class CurrencyGuardTest extends TestCase {

	private function live_in( string $hostelmate, string $hbook ): void {
		Settings::set_mode( Config::MODE_LIVE );
		State::set( 'property', array( 'id' => 'p', 'currency' => $hostelmate, 'rooms' => array() ) );
		\WpStub::$options['hb_currency'] = $hbook;
	}

	public function test_same_currency_is_ok(): void {
		$this->live_in( 'USD', 'usd' );
		$this->assertTrue( SyncRunner::currency_ok() );
	}

	public function test_different_currency_at_connect_is_not_ok(): void {
		$this->live_in( 'USD', 'MXN' );
		$this->assertFalse( SyncRunner::currency_ok() );
	}

	public function test_hostelmate_changed_its_currency_later(): void {
		// Connected in USD; a later answer from HostelMate said otherwise and opened the alert.
		$this->live_in( 'USD', 'USD' );
		Alerts::open( Messages::SYNC_CURRENCY, Config::ALERT_LEVEL_RED );
		$this->assertFalse( SyncRunner::currency_ok() );
		Alerts::resolve_code( Messages::SYNC_CURRENCY );
		$this->assertTrue( SyncRunner::currency_ok() );
	}

	public function test_bookings_wait_while_currencies_differ(): void {
		$this->live_in( 'MXN', 'USD' );
		$this->assertSame( 0, BookingRunner::run() );
		$this->assertSame( array(), \WpStub::$requests, 'nothing is sent to HostelMate' );
	}

	/** Agrees to USD → MXN at 20, refreshed now. */
	private function agree(): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( array( 'id' => 1, 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '20.00000000', 'fetched_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ) ),
		);
		$this->assertSame( Messages::FX_AGREED, Fx::agree( 'USD', 'MXN', 1, time() ) );
		\WpStub::$requests = array();
	}

	public function test_same_currency_bookings_never_ask_for_a_rate(): void {
		$this->live_in( 'USD', 'USD' );
		$this->assertNull( SyncRunner::booking_fx() );
		$this->assertArrayNotHasKey( Config::OPT_FX, \WpStub::$options );
		$this->assertSame( array(), \WpStub::$requests );
	}

	public function test_agreed_conversion_gives_bookings_the_rate(): void {
		$this->live_in( 'USD', 'MXN' );
		$this->assertNull( SyncRunner::booking_fx(), 'not agreed: wait' );
		$this->agree();
		$pair = SyncRunner::booking_fx();
		$this->assertNotNull( $pair );
		$this->assertSame( '20.00000000', $pair['web_rate'] );
		$this->assertSame( array(), \WpStub::$requests, 'bookings never fetch the list' );
	}

	public function test_bookings_wait_when_hostelmate_answered_in_another_currency(): void {
		$this->live_in( 'USD', 'MXN' );
		$this->agree();
		Alerts::open( Messages::FX_PAIR_CHANGED, Config::ALERT_LEVEL_RED );
		$this->assertNull( SyncRunner::booking_fx() );
		Alerts::resolve_code( Messages::FX_PAIR_CHANGED );
		Alerts::open( Messages::SYNC_CURRENCY, Config::ALERT_LEVEL_RED );
		$this->assertNull( SyncRunner::booking_fx() );
	}

	public function test_bookings_wait_for_a_pair_not_agreed(): void {
		$this->live_in( 'USD', 'MXN' );
		$this->agree();
		$this->live_in( 'EUR', 'MXN' ); // HostelMate moved to EUR.
		$this->assertNull( SyncRunner::booking_fx() );
		$this->assertSame( 0, BookingRunner::run() );
		$this->assertSame( array(), \WpStub::$requests );
	}
}
