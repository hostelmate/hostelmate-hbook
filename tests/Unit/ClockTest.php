<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\Clock;

final class ClockTest extends TestCase {

	public function test_nights_exclude_check_out(): void {
		$this->assertSame( array( '2026-12-03', '2026-12-04' ), Clock::nights( '2026-12-03', '2026-12-05' ) );
		$this->assertSame( array(), Clock::nights( '2026-12-03', '2026-12-03' ) );
	}

	public function test_month_year_and_leap_days(): void {
		$this->assertSame( '2027-01-01', Clock::add_days( '2026-12-31', 1 ) );
		$this->assertSame( '2028-02-29', Clock::add_days( '2028-02-28', 1 ) );
		$this->assertSame( '2026-11-30', Clock::add_days( '2026-12-01', -1 ) );
		$this->assertSame( 366, Clock::days_between( '2028-01-01', '2029-01-01' ) );
		$this->assertSame( -2, Clock::days_between( '2026-12-05', '2026-12-03' ) );
	}

	public function test_daylight_saving_never_shifts_a_date(): void {
		// US clocks jump forward on 8 March 2026; date maths must not lose or repeat a day.
		$this->assertSame( array( '2026-03-07', '2026-03-08', '2026-03-09' ), Clock::nights( '2026-03-07', '2026-03-10' ) );
		$this->assertSame( '2026-11-02', Clock::add_days( '2026-10-31', 2 ) );
	}

	public function test_today_follows_the_property_zone(): void {
		$at = gmmktime( 5, 30, 0, 11, 24, 2026 ); // 05:30 UTC on 24 Nov 2026.
		$this->assertSame( '2026-11-23', Clock::today( new \DateTimeZone( 'America/Mexico_City' ), $at ) );
		$this->assertSame( '2026-11-24', Clock::today( new \DateTimeZone( 'Asia/Dubai' ), $at ) );
	}

	public function test_refuses_bad_dates(): void {
		$this->expectException( \InvalidArgumentException::class );
		Clock::add_days( '2026-02-30', 1 );
	}
}
