<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\HBook\Occupancy;
use HostelMate\HBook\HBook\Prices;
use HostelMate\HBook\HBook\Units;

final class HBookRulesTest extends TestCase {

	public function test_which_statuses_hold_a_unit(): void {
		foreach ( array( 'new', 'confirmed', 'waiting_payment', 'multi_resa_pending', 'something_new' ) as $status ) {
			$this->assertTrue( Occupancy::holds_unit( $status, false ), $status );
		}
		$this->assertFalse( Occupancy::holds_unit( 'cancelled', false ) );
		$this->assertFalse( Occupancy::holds_unit( 'pending', false ) );
		$this->assertFalse( Occupancy::holds_unit( 'waiting_payment', true ) );
		$this->assertTrue( Occupancy::holds_unit( 'new', true ) );
	}

	public function test_block_nights_end_before_to_date(): void {
		$b = array( 'from_date' => '2026-12-03', 'to_date' => '2026-12-05' );
		$this->assertSame( array( '2026-12-03', '2026-12-04' ), Occupancy::block_nights( $b, '2026-12-01', '2026-12-31' ) );
		$this->assertSame( array( '2026-12-04' ), Occupancy::block_nights( $b, '2026-12-04', '2026-12-31' ) );
	}

	public function test_zero_length_block_takes_the_night_before_and_the_day(): void {
		$b = array( 'from_date' => '2026-12-05', 'to_date' => '2026-12-05' );
		$this->assertSame( array( '2026-12-04', '2026-12-05' ), Occupancy::block_nights( $b, '2026-12-01', '2026-12-31' ) );
		$this->assertSame( array( '2026-12-05' ), Occupancy::block_nights( $b, '2026-12-05', '2026-12-31' ) );
	}

	public function test_backwards_block_takes_nothing(): void {
		$this->assertSame( array(), Occupancy::block_nights( array( 'from_date' => '2026-12-05', 'to_date' => '2026-12-03' ), '2026-12-01', '2026-12-31' ) );
	}

	public function test_taken_combines_reservations_and_blocks(): void {
		$rows  = array(
			'reservations' => array(
				array( 'id' => 1, 'accom_id' => 10, 'accom_num' => 1, 'check_in' => '2026-12-03', 'check_out' => '2026-12-05', 'status' => 'confirmed', 'origin' => 'website', 'synchro_id' => '' ),
				array( 'id' => 2, 'accom_id' => 10, 'accom_num' => 2, 'check_in' => '2026-12-03', 'check_out' => '2026-12-05', 'status' => 'cancelled', 'origin' => 'website', 'synchro_id' => '' ),
			),
			'blocks'       => array(
				array( 'id' => 5, 'accom_id' => 10, 'accom_num' => 2, 'from_date' => '2026-12-06', 'to_date' => '2026-12-07', 'linked_resa_id' => 0, 'is_prepa_time' => false, 'uid' => 'abc' ),
				array( 'id' => 6, 'accom_id' => 10, 'accom_num' => 3, 'from_date' => '2026-12-03', 'to_date' => '2026-12-04', 'linked_resa_id' => 0, 'is_prepa_time' => false, 'uid' => 'hostelmate-x' ),
			),
		);
		$all = Occupancy::taken( $rows, '2026-12-01', '2026-12-10', true );
		$this->assertSame( array( '2026-12-03', '2026-12-04' ), array_keys( $all['10:1'] ) );
		$this->assertSame( array( '2026-12-06' ), array_keys( $all['10:2'] ) );
		$this->assertArrayHasKey( '10:3', $all );
		$foreign = Occupancy::taken( $rows, '2026-12-01', '2026-12-10', false );
		$this->assertArrayNotHasKey( '10:3', $foreign );
	}

	/** @return array<int, array{priority: string, dates: list<array{row_id: int, start: string, end: string, days: list<int>}>}> */
	private static function seasons(): array {
		$all = array( 0, 1, 2, 3, 4, 5, 6 );
		return array(
			1 => array( 'priority' => 'low', 'dates' => array( array( 'row_id' => 1, 'start' => '2024-01-01', 'end' => '2030-12-31', 'days' => $all ) ) ),
			2 => array( 'priority' => '', 'dates' => array( array( 'row_id' => 5, 'start' => '2026-12-01', 'end' => '2026-12-31', 'days' => $all ) ) ),
			3 => array( 'priority' => '', 'dates' => array( array( 'row_id' => 2, 'start' => '2026-12-10', 'end' => '2026-12-12', 'days' => $all ) ) ),
			4 => array( 'priority' => 'high', 'dates' => array( array( 'row_id' => 9, 'start' => '2026-12-20', 'end' => '2026-12-20', 'days' => $all ) ) ),
			5 => array( 'priority' => 'high', 'dates' => array( array( 'row_id' => 8, 'start' => '2026-12-01', 'end' => '2026-12-31', 'days' => array( 4, 5 ) ) ) ), // Fri, Sat.
		);
	}

	public function test_season_priority_order_and_weekdays(): void {
		$s = self::seasons();
		$this->assertSame( 1, Prices::season_for( $s, '2026-11-15' ) );          // Only the low one covers it.
		$this->assertSame( 3, Prices::season_for( $s, '2026-12-10' ) );          // Thu: two '' seasons; lower date-row id wins.
		$this->assertSame( 5, Prices::season_for( $s, '2026-12-11' ) );          // Fri: high weekday season wins.
		$this->assertSame( 2, Prices::season_for( $s, '2026-12-14' ) );          // Mon: high season skips Mondays.
		$this->assertSame( 4, Prices::season_for( $s, '2026-12-20' ) );          // Sun: one-day high season.
		$this->assertSame( 0, Prices::season_for( array(), '2026-12-20' ) );
	}

	public function test_season_skips_the_plugins_own(): void {
		$this->assertSame( 2, Prices::season_for( self::seasons(), '2026-12-20', array( 4 ) ) );
		$this->assertSame( 3, Prices::season_for( self::seasons(), '2026-12-11', array( 5 ) ) ); // Season 3 covers the 11th with a lower date-row id.
	}

	public function test_many_nights_at_once_match_the_single_night_rule(): void {
		$s = self::seasons();
		// Any order, duplicates, a night nothing covers: one answer per night, the same as season_for's fixed cases above.
		$this->assertSame(
			array(
				'2023-06-01' => 0,
				'2026-11-15' => 1,
				'2026-12-10' => 3,
				'2026-12-11' => 5,
				'2026-12-14' => 2,
				'2026-12-20' => 4,
			),
			Prices::season_per_night( $s, array( '2026-12-20', '2026-11-15', '2026-12-11', '2023-06-01', '2026-12-14', '2026-12-10', '2026-12-11' ) )
		);
		$this->assertSame( array( '2026-12-11' => 3, '2026-12-20' => 2 ), Prices::season_per_night( $s, array( '2026-12-20', '2026-12-11' ), array( 4, 5 ) ) );
		$this->assertSame( array( '2026-12-11' => 5, '2026-12-14' => 0 ), Prices::season_per_night( $s, array( '2026-12-14', '2026-12-11' ), array(), array( 'high' ) ) );
		$this->assertSame( array(), Prices::season_per_night( $s, array() ) );
	}

	public function test_night_rates_keep_the_first_plain_match(): void {
		$rate  = static fn( int $id, string $amount, array $accoms, array $seasons, string $type = 'accom', int $nights = 1, array $rules = array() ): array => compact( 'id', 'type', 'amount', 'nights', 'accoms', 'seasons', 'rules' );
		$rates = array(
			$rate( 1, '9.00', array( 10 ), array( 1 ), 'accom', 1, array( 3 ) ), // Rule rate: left out.
			$rate( 2, '8.00', array( 10 ), array( 1 ), 'accom', 3 ),             // Package rate: left out.
			$rate( 3, '7.00', array( 10, 11 ), array( 1, 2 ), 'extra_adults' ),  // Not an accommodation price.
			$rate( 4, '50.00', array( 10, 11 ), array( 1 ) ),
			$rate( 5, '60.00', array( 10 ), array( 1, 2 ) ),                     // (10, 1) already taken by rate 4.
		);
		$this->assertSame( array( 10 => array( 1 => '50.00', 2 => '60.00' ), 11 => array( 1 => '50.00' ) ), Prices::night_rates( $rates ) );
		foreach ( array( array( 10, 1 ), array( 10, 2 ), array( 11, 1 ), array( 11, 2 ), array( 12, 1 ) ) as list( $accom, $season ) ) {
			$this->assertSame( Prices::night_rate( $rates, $accom, $season ), Prices::night_rates( $rates )[ $accom ][ $season ] ?? null );
		}
	}

	public function test_schema_problems_are_named_for_the_owner(): void {
		$this->assertSame( '', \HostelMate\HBook\HBook\Schema::summary( array() ) );
		$this->assertSame( 'not InnoDB: wp_hb_accom_blocked', \HostelMate\HBook\HBook\Schema::summary( array( 'not_innodb:accom_blocked' ) ) );
		$this->assertSame( 'missing column: wp_hb_seasons_dates.days (+1 more)', \HostelMate\HBook\HBook\Schema::summary( array( 'missing_column:seasons_dates.days', 'missing_table:ical' ) ) );
	}

	public function test_short_names(): void {
		$this->assertSame( 'Sunrise', Units::short_name( "Sunrise | T\u{200A}E\u{200A}S\u{200A}T, Beach Town" ) );
		$this->assertSame( 'Garden, N°65', Units::short_name( "Garden, N°65 | female dorm | T E S T" ) );
		$this->assertSame( 'Moon', Units::short_name( "\u{00A0}Moon\u{00A0}" ) );
		$this->assertSame( '| odd', Units::short_name( '| odd' ) );
	}
}
