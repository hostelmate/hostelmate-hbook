<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Sync\BlockPlanner;
use HostelMate\HBook\Sync\Brake;
use HostelMate\HBook\Sync\PricePlanner;

final class PlannersTest extends TestCase {

	private const DORM  = 'dorm-room';
	private const HUT   = 'hut-room';
	private const WHOLE = 'whole-room';
	private const BED_A = 'bed-a-room';
	private const BED_B = 'bed-b-room';

	/** @return array<int, array<string, mixed>> */
	private static function seasons(): array {
		$all = array( 0, 1, 2, 3, 4, 5, 6 );
		return array(
			1  => array( 'priority' => 'low', 'dates' => array( array( 'row_id' => 1, 'start' => '2024-01-01', 'end' => '2030-12-31', 'days' => $all ) ) ),
			2  => array( 'priority' => '', 'dates' => array( array( 'row_id' => 2, 'start' => '2026-12-04', 'end' => '2026-12-05', 'days' => $all ) ) ),
			99 => array( 'priority' => 'high', 'dates' => array( array( 'row_id' => 3, 'start' => '2026-12-01', 'end' => '2026-12-31', 'days' => $all ) ) ), // The plugin's own.
		);
	}

	/** @return list<array<string, mixed>> */
	private static function rates(): array {
		$r = static fn( int $id, string $amount, array $accoms, array $seasons, string $type = 'accom', int $nights = 1, array $rules = array() ): array => compact( 'id', 'type', 'amount', 'nights', 'accoms', 'seasons', 'rules' );
		return array(
			$r( 1, '300.00', array( 10, 11, 20 ), array( 1 ) ),
			$r( 2, '450.00', array( 10, 11, 20 ), array( 2 ) ),
			$r( 3, '1800.00', array( 20 ), array( 1 ), 'accom', 7 ),     // Package price: never used as a night rate.
			$r( 4, '10.00', array( 20 ), array( 1 ), 'accom', 1, array( 5 ) ), // Rule rate: never used as a night rate.
		);
	}

	private static function nights(): array {
		return array( '2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06' );
	}

	public function test_prices_group_nights_and_keep_unmatched_rooms(): void {
		$hm = array( self::DORM => array() );
		foreach ( self::nights() as $n ) {
			$hm[ self::DORM ][ $n ] = array( 'price' => 50000 );
		}
		$plan = PricePlanner::plan( self::nights(), self::seasons(), array( 99 ), self::rates(), array( 10, 11, 20 ), array( 10 => self::DORM, 11 => self::DORM ), $hm, array() );

		// Matched rooms get HostelMate's 500.00 every night; room 20 keeps its own season price.
		$this->assertSame( '500.00', $plan['prices'][10]['2026-12-04'] );
		$this->assertSame( '300.00', $plan['prices'][20]['2026-12-03'] );
		$this->assertSame( '450.00', $plan['prices'][20]['2026-12-04'] );
		// Two groups: base season 1 (3rd, 6th) and base season 2 (4th–5th).
		$this->assertCount( 2, $plan['seasons'] );
		$ranges = array_column( $plan['seasons'], 'ranges' );
		$this->assertContains( array( array( '2026-12-03', '2026-12-03' ), array( '2026-12-06', '2026-12-06' ) ), $ranges );
		$this->assertContains( array( array( '2026-12-04', '2026-12-05' ) ), $ranges );
		// The plugin's own high season is never a "foreign" high season.
		$this->assertSame( array(), $plan['foreign_high'] );
		$this->assertSame( array(), $plan['no_season'] );
		// Rooms sharing a price share a rate row.
		$first = $plan['seasons'][0]['rates'];
		$this->assertSame( array( 10, 11 ), $first[0]['accoms'] );
	}

	public function test_missing_hostelmate_price_falls_back_and_foreign_high_seasons_are_reported(): void {
		$seasons      = self::seasons();
		$seasons[50]  = array( 'priority' => 'high', 'dates' => array( array( 'row_id' => 9, 'start' => '2026-12-06', 'end' => '2026-12-06', 'days' => array( 0, 1, 2, 3, 4, 5, 6 ) ) ) );
		$hm           = array( self::DORM => array( '2026-12-03' => array( 'price' => null ), '2026-12-04' => array( 'price' => 70000 ) ) );
		$plan         = PricePlanner::plan( array( '2026-12-03', '2026-12-04', '2026-12-06' ), $seasons, array( 99 ), self::rates(), array( 10 ), array( 10 => self::DORM ), $hm, array() );
		$this->assertSame( '300.00', $plan['prices'][10]['2026-12-03'] ); // No price, no last price: base rate.
		$this->assertSame( array( '2026-12-06' ), $plan['foreign_high'] );
		$this->assertSame( 70000, $plan['last_price'][ self::DORM ] );

		$again = PricePlanner::plan( array( '2026-12-03' ), $seasons, array( 99 ), self::rates(), array( 10 ), array( 10 => self::DORM ), $hm, array( self::DORM => 65000 ) );
		$this->assertSame( '650.00', $again['prices'][10]['2026-12-03'] ); // Last known HostelMate price wins over HBook's.
	}

	public function test_rate_rows_never_link_too_many_rooms(): void {
		$accoms = range( 1000, 1000 + Config::MAX_ACCOMS_PER_RATE * 2 + 3 );
		$map    = array_fill_keys( $accoms, self::DORM );
		$plan   = PricePlanner::plan( array( '2026-12-03' ), self::seasons(), array( 99 ), self::rates(), $accoms, $map, array( self::DORM => array( '2026-12-03' => array( 'price' => 100 ) ) ), array() );
		$rows   = $plan['seasons'][0]['rates'];
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$this->assertLessThanOrEqual( Config::MAX_ACCOMS_PER_RATE, count( $row['accoms'] ) );
		}
	}

	public function test_ranges(): void {
		$this->assertSame( array( array( '2026-12-30', '2027-01-02' ), array( '2027-01-05', '2027-01-05' ) ), PricePlanner::ranges( array( '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-05' ) ) );
	}

	/** Rows helper. */
	private static function rows( array $resas = array(), array $blocks = array() ): array {
		$r = array();
		foreach ( $resas as $i => $x ) {
			$r[] = $x + array( 'id' => $i + 1, 'status' => 'confirmed', 'origin' => 'website', 'synchro_id' => '' );
		}
		$b = array();
		foreach ( $blocks as $i => $x ) {
			$b[] = $x + array( 'id' => 100 + $i, 'linked_resa_id' => 0, 'is_prepa_time' => false, 'uid' => 'x' . $i );
		}
		return array( 'reservations' => $r, 'blocks' => $b );
	}

	private static function hm( string $room, int $available, bool $closed = false ): array {
		$out = array();
		foreach ( self::nights() as $n ) {
			$out[ $n ] = array( 'available' => $available, 'closed' => $closed );
		}
		return array( $room => $out );
	}

	public function test_blocks_close_the_difference_from_the_last_units(): void {
		$units = array( self::DORM => array( '74:1', '76:1', '78:1' ) );
		$plan  = BlockPlanner::plan( self::nights(), $units, self::hm( self::DORM, 1 ), self::rows(), false, array() );
		$this->assertSame( array( '76:1', '78:1' ), array_keys( $plan['wanted'] ) );
		$this->assertSame( self::nights(), $plan['wanted']['78:1'] );
		$this->assertSame( array(), $plan['mismatches'] );
	}

	public function test_hbook_bookings_count_before_blocking(): void {
		$units = array( self::DORM => array( '74:1', '76:1', '78:1' ) );
		$rows  = self::rows( array( array( 'accom_id' => 74, 'accom_num' => 1, 'check_in' => '2026-12-03', 'check_out' => '2026-12-05' ) ) );
		$plan  = BlockPlanner::plan( self::nights(), $units, self::hm( self::DORM, 1 ), $rows, false, array() );
		// 3rd–4th: 74 is booked, 2 free, 1 sellable → block 1 (78). 5th–6th: 3 free → block 2 (76, 78).
		$this->assertSame( array( '2026-12-05', '2026-12-06' ), $plan['wanted']['76:1'] );
		$this->assertSame( self::nights(), $plan['wanted']['78:1'] );
	}

	public function test_units_already_blocked_stay_blocked(): void {
		$units = array( self::DORM => array( '74:1', '76:1', '78:1' ) );
		$rows  = self::rows( array(), array( array( 'accom_id' => 74, 'accom_num' => 1, 'from_date' => '2026-12-03', 'to_date' => '2026-12-07', 'uid' => 'hostelmate-abc' ) ) );
		$plan  = BlockPlanner::plan( self::nights(), $units, self::hm( self::DORM, 2 ), $rows, false, array() );
		$this->assertSame( array( '74:1' ), array_keys( $plan['wanted'] ) ); // Our existing block on 74 is kept, not moved to 78.
	}

	public function test_closed_night_blocks_everything_free(): void {
		$units = array( self::HUT => array( '20:1' ) );
		$plan  = BlockPlanner::plan( self::nights(), $units, self::hm( self::HUT, 1, true ), self::rows(), false, array() );
		$this->assertSame( self::nights(), $plan['wanted']['20:1'] );
		$plan = BlockPlanner::plan( self::nights(), $units, array(), self::rows(), false, array() );
		$this->assertSame( self::nights(), $plan['wanted']['20:1'] ); // No answer for a night = closed.
	}

	public function test_mismatch_when_hbook_holds_what_hostelmate_does_not_know(): void {
		$units = array( self::HUT => array( '20:1' ) );
		$rows  = self::rows(
			array( array( 'accom_id' => 20, 'accom_num' => 1, 'check_in' => '2026-12-03', 'check_out' => '2026-12-04', 'origin' => 'airbnb' ) ),
			array(
				array( 'accom_id' => 20, 'accom_num' => 1, 'from_date' => '2026-12-05', 'to_date' => '2026-12-06' ),
				array( 'accom_id' => 20, 'accom_num' => 1, 'from_date' => '2026-12-06', 'to_date' => '2026-12-07', 'is_prepa_time' => true ),
			)
		);
		$plan = BlockPlanner::plan( self::nights(), $units, self::hm( self::HUT, 1 ), $rows, false, array() );
		$this->assertSame( array( '2026-12-03', '2026-12-05' ), array_column( $plan['mismatches'], 'night' ) );
		$this->assertSame( array( 'resa:1' ), $plan['mismatches'][0]['candidates'] );
		$this->assertSame( array( 'block:100' ), $plan['mismatches'][1]['candidates'] );
		$this->assertArrayNotHasKey( '20:1', $plan['wanted'] ); // Nothing to block: the unit is already taken.
	}

	public function test_website_bookings_still_to_send_are_not_mismatches(): void {
		// Found live 2026-11-24: before go-live no website booking is in HostelMate yet, so every one of them was a
		// mismatch, and go-live (which sends them) was blocked for good.
		$units = array( self::DORM => array( '74:1', '76:1' ) );
		$web   = array( 'accom_id' => 74, 'accom_num' => 1, 'check_in' => '2026-12-03', 'check_out' => '2026-12-05' ); // resa:1, website.
		$ota   = array( 'accom_id' => 76, 'accom_num' => 1, 'check_in' => '2026-12-04', 'check_out' => '2026-12-05', 'origin' => 'airbnb' ); // resa:2.
		$hm    = self::hm( self::DORM, 2 ); // HostelMate knows neither.

		// Without the list of created bookings (any caller but SyncRunner::context()): every booking counts, as before.
		$plan = BlockPlanner::plan( self::nights(), $units, $hm, self::rows( array( $web, $ota ) ), false, array() );
		$this->assertSame( array( '2026-12-03', '2026-12-04' ), array_column( $plan['mismatches'], 'night' ) );

		// Not created yet: the website booking is the booking loop's job. The iCal copy on the 4th still is a mismatch,
		// missing one unit, and only it is named.
		$plan = BlockPlanner::plan( self::nights(), $units, $hm, self::rows( array( $web, $ota ) ) + array( 'created' => array() ), false, array() );
		$this->assertSame( array( '2026-12-04' ), array_column( $plan['mismatches'], 'night' ) );
		$this->assertSame( 1, $plan['mismatches'][0]['missing'] );
		$this->assertSame( array( 'resa:2' ), $plan['mismatches'][0]['candidates'] );
		$this->assertSame( array(), $plan['wanted'] ); // Blocks are unchanged: HBook still holds the unit.

		// Created in HostelMate, yet HostelMate counts the bed free (staff cancelled or moved it there, scenario 33): a mismatch again.
		$plan = BlockPlanner::plan( self::nights(), $units, $hm, self::rows( array( $web ) ) + array( 'created' => array( 1 ) ), false, array() );
		$this->assertSame( array( '2026-12-03', '2026-12-04' ), array_column( $plan['mismatches'], 'night' ) );
		$this->assertSame( array( 'resa:1' ), $plan['mismatches'][0]['candidates'] );

		// Sent and known: HostelMate already counts it, so nothing is missing.
		$plan = BlockPlanner::plan( self::nights(), $units, array( self::DORM => array_merge( self::hm( self::DORM, 2 )[ self::DORM ], array( '2026-12-03' => array( 'available' => 1, 'closed' => false ), '2026-12-04' => array( 'available' => 1, 'closed' => false ) ) ) ), self::rows( array( $web ) ) + array( 'created' => array( 1 ) ), false, array() );
		$this->assertSame( array(), $plan['mismatches'] );
	}

	public function test_whole_room_and_its_beds_block_each_other_inside_hbook(): void {
		$units   = array( self::WHOLE => array( '600:1' ), self::BED_A => array( '62:1' ), self::BED_B => array( '63:1' ) );
		$hm      = self::hm( self::WHOLE, 1 ) + self::hm( self::BED_A, 1 ) + self::hm( self::BED_B, 1 );
		$hybrids = array( self::WHOLE => array( self::BED_A, self::BED_B ) );
		$rows    = self::rows(
			array(
				array( 'accom_id' => 62, 'accom_num' => 1, 'check_in' => '2026-12-03', 'check_out' => '2026-12-04' ),
				array( 'accom_id' => 600, 'accom_num' => 1, 'check_in' => '2026-12-05', 'check_out' => '2026-12-06' ),
			)
		);
		$plan = BlockPlanner::plan( self::nights(), $units, $hm, $rows, false, $hybrids );
		$this->assertSame( array( '2026-12-03' ), $plan['wanted']['600:1'] );           // A bed booked → whole room closed.
		$this->assertSame( array( '2026-12-05' ), $plan['wanted']['62:1'] );            // Whole room booked → beds closed.
		$this->assertSame( array( '2026-12-05' ), $plan['wanted']['63:1'] );
	}

	public function test_brake(): void {
		$window = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$window[] = gmdate( 'Y-m-d', strtotime( '2026-12-01 +' . $i . ' day' ) );
		}
		$units_of = array( 10 => array( '10:1' ), 11 => array( '11:1' ) );
		$now      = array( 10 => array_fill_keys( $window, '300.00' ), 11 => array_fill_keys( $window, '300.00' ) );
		$small    = $now;
		$small[10]['2026-12-01'] = '900.00';
		$this->assertSame( Brake::OK, Brake::check( $window, array( 10, 11 ), $units_of, $now, $small, array(), array() )['verdict'] );

		$closed = array( '10:1' => $window, '11:1' => $window );
		$this->assertSame( Brake::TOO_BIG, Brake::check( $window, array( 10, 11 ), $units_of, $now, $now, array(), $closed )['verdict'] );

		$cheap = $now;
		$cheap[11]['2026-12-02'] = '0.50';
		$this->assertSame( Brake::PRICE_LOW, Brake::check( $window, array( 10, 11 ), $units_of, $now, $cheap, array(), array() )['verdict'] );
	}
}
