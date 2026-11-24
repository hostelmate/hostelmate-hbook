<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Admin\Setup;
use HostelMate\HBook\Config;
use HostelMate\HBook\Sync\GoLive;

/** The guided setup: which step is next, and that the URL can't skip ahead. */
final class SetupStepsTest extends TestCase {

	public function test_steps_follow_each_other(): void {
		$now = time();
		$this->assertSame( Setup::CONNECT, Setup::current( Setup::finished( false, false, null, null ) ) );
		$this->assertSame( Setup::ROOMS, Setup::current( Setup::finished( true, false, null, null ) ) );
		// Suggested matches that were never saved don't count.
		$this->assertSame( Setup::ROOMS, Setup::current( Setup::finished( true, true, null, null ) ) );
		$this->assertSame( Setup::CHECK, Setup::current( Setup::finished( true, true, $now - 60, null ) ) );
		$this->assertSame( Setup::LIVE, Setup::current( Setup::finished( true, true, $now - 60, $now ) ) );
	}

	public function test_a_check_must_be_newer_than_the_rooms_and_fresh(): void {
		$now = time();
		// Rooms changed after the check: check again.
		$this->assertSame( Setup::CHECK, Setup::current( Setup::finished( true, true, $now, $now - 60 ) ) );
		// A check older than 30 minutes: check again.
		$old = $now - Config::PREVIEW_FRESH_FOR - 1;
		$this->assertSame( Setup::CHECK, Setup::current( Setup::finished( true, true, $old - 10, $old ) ) );
		// Rooms with errors send you back to the rooms, whatever was checked.
		$this->assertSame( Setup::ROOMS, Setup::current( Setup::finished( true, false, $now - 60, $now ) ) );
		// No key: back to the start.
		$this->assertSame( Setup::CONNECT, Setup::current( Setup::finished( false, true, $now - 60, $now ) ) );
	}

	public function test_the_url_can_open_earlier_steps_but_not_skip_ahead(): void {
		$this->assertSame( Setup::CONNECT, Setup::shown( 'connect', Setup::CHECK ) );
		$this->assertSame( Setup::ROOMS, Setup::shown( 'rooms', Setup::CHECK ) );
		$this->assertSame( Setup::CHECK, Setup::shown( 'live', Setup::CHECK ) );
		$this->assertSame( Setup::ROOMS, Setup::shown( 'live', Setup::ROOMS ) );
		$this->assertSame( Setup::ROOMS, Setup::shown( '', Setup::ROOMS ) );
		$this->assertSame( Setup::ROOMS, Setup::shown( '<script>', Setup::ROOMS ) );
	}

	public function test_go_live_needs_a_matched_room_and_otherwise_only_a_tick(): void {
		$room    = static fn( bool $ok ): array => array( 'ok' => $ok, 'required' => true );
		$warning = static fn( bool $ok ): array => array( 'ok' => $ok, 'required' => false );
		// Everything fine: no tick needed.
		$this->assertTrue( GoLive::allowed( array( $room( true ), $warning( true ) ), false ) );
		// A warning (e.g. bookings HostelMate doesn't know): only with the owner's tick.
		$this->assertFalse( GoLive::allowed( array( $room( true ), $warning( false ) ), false ) );
		$this->assertTrue( GoLive::allowed( array( $room( true ), $warning( false ), $warning( false ) ), true ) );
		// No matched room: never, tick or not.
		$this->assertFalse( GoLive::allowed( array( $room( false ), $warning( true ) ), true ) );
		$this->assertFalse( GoLive::allowed( array( $room( false ) ), false ) );
	}
}
