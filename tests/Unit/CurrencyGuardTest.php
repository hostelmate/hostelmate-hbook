<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\BookingRunner;
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
}
