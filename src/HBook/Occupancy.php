<?php
/**
 * Which units HBook considers taken on which nights, exactly as HBook decides it
 * (hbook/database-actions/database-actions.php:658-710):
 *  - a reservation takes its nights unless it is cancelled or pending
 *    (waiting_payment also doesn't count when hb_resa_paid_has_confirmation = yes);
 *  - any block takes nights from_date .. to_date - 1;
 *  - a zero-length block on day D collides with every stay touching D, so it takes D-1 and D.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Rows from \HostelMate\HBook\Types
 */
final class Occupancy {

	/**
	 * Reservations and blocks touching [$from, $to), normalised.
	 *
	 * @param string $from First night.
	 * @param string $to   Day after the last night.
	 * @return Rows
	 */
	public static function read( string $from, string $to ): array {
		global $wpdb;
		$resa   = Schema::table( 'resa' );
		$block  = Schema::table( 'accom_blocked' );
		$r_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, accom_id, accom_num, check_in, check_out, status, origin, synchro_id FROM %i WHERE check_in <= %s AND check_out >= %s', $resa, $to, $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$b_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, accom_id, accom_num, from_date, to_date, linked_resa_id, is_prepa_time, uid FROM %i WHERE from_date <= %s AND to_date >= %s', $block, $to, $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$reservations = array();
		foreach ( (array) $r_rows as $r ) {
			$reservations[] = array(
				'id'         => (int) $r['id'],
				'accom_id'   => (int) $r['accom_id'],
				'accom_num'  => (int) $r['accom_num'],
				'check_in'   => (string) $r['check_in'],
				'check_out'  => (string) $r['check_out'],
				'status'     => (string) $r['status'],
				'origin'     => (string) $r['origin'],
				'synchro_id' => (string) $r['synchro_id'],
			);
		}
		$blocks = array();
		foreach ( (array) $b_rows as $b ) {
			$blocks[] = array(
				'id'             => (int) $b['id'],
				'accom_id'       => (int) $b['accom_id'],
				'accom_num'      => (int) $b['accom_num'],
				'from_date'      => (string) $b['from_date'],
				'to_date'        => (string) $b['to_date'],
				'linked_resa_id' => (int) $b['linked_resa_id'],
				'is_prepa_time'  => '1' === (string) $b['is_prepa_time'],
				'uid'            => (string) $b['uid'],
			);
		}
		return array(
			'reservations' => $reservations,
			'blocks'       => $blocks,
		);
	}

	/**
	 * HBook's rule for whether a reservation holds its unit.
	 *
	 * @param string $status               hb_resa.status.
	 * @param bool   $paid_needs_confirmed hb_resa_paid_has_confirmation = yes.
	 */
	public static function holds_unit( string $status, bool $paid_needs_confirmed ): bool {
		if ( 'cancelled' === $status || 'pending' === $status ) {
			return false;
		}
		return ! ( 'waiting_payment' === $status && $paid_needs_confirmed );
	}

	/** True when this site makes paid bookings wait for a confirmation (then waiting_payment holds nothing). */
	public static function paid_needs_confirmation(): bool {
		return 'yes' === get_option( 'hb_resa_paid_has_confirmation' );
	}

	/**
	 * True for a block the plugin wrote.
	 *
	 * @param array{uid: string} $block A block from read().
	 */
	public static function is_own_block( array $block ): bool {
		return str_starts_with( $block['uid'], Config::BLOCK_UID_PREFIX );
	}

	/**
	 * Nights a block takes, inside [$from, $to).
	 *
	 * @param array{from_date: string, to_date: string} $block A block from read().
	 * @param string                                    $from  First night of interest.
	 * @param string                                    $to    Day after the last night of interest.
	 * @return list<string>
	 */
	public static function block_nights( array $block, string $from, string $to ): array {
		if ( $block['from_date'] === $block['to_date'] ) {
			$nights = array( Clock::add_days( $block['from_date'], -1 ), $block['from_date'] );
		} elseif ( $block['to_date'] < $block['from_date'] ) {
			return array();
		} else {
			$nights = Clock::nights( max( $block['from_date'], $from ), min( $block['to_date'], $to ) );
		}
		return array_values( array_filter( $nights, static fn( string $n ): bool => $n >= $from && $n < $to ) );
	}

	/**
	 * Nights a reservation takes, inside [$from, $to).
	 *
	 * @param array{check_in: string, check_out: string} $resa A reservation from read().
	 * @param string                                     $from First night of interest.
	 * @param string                                     $to   Day after the last night of interest.
	 * @return list<string>
	 */
	public static function resa_nights( array $resa, string $from, string $to ): array {
		$start = max( $resa['check_in'], $from );
		$end   = min( $resa['check_out'], $to );
		return $start < $end ? Clock::nights( $start, $end ) : array();
	}

	/**
	 * "accom_id:unit" → night → true, for everything that takes a unit, optionally leaving out the plugin's own blocks.
	 *
	 * @param Rows $rows What read() returned.
	 * @param string $from               First night.
	 * @param string $to                 Day after the last night.
	 * @param bool   $include_own_blocks Count the plugin's own blocks too (true = HBook's full view).
	 * @return array<string, array<string, true>>
	 */
	public static function taken( array $rows, string $from, string $to, bool $include_own_blocks ): array {
		$paid_needs = self::paid_needs_confirmation();
		$taken      = array();
		foreach ( $rows['reservations'] as $r ) {
			if ( self::holds_unit( $r['status'], $paid_needs ) ) {
				foreach ( self::resa_nights( $r, $from, $to ) as $night ) {
					$taken[ $r['accom_id'] . ':' . $r['accom_num'] ][ $night ] = true;
				}
			}
		}
		foreach ( $rows['blocks'] as $b ) {
			if ( $include_own_blocks || ! self::is_own_block( $b ) ) {
				foreach ( self::block_nights( $b, $from, $to ) as $night ) {
					$taken[ $b['accom_id'] . ':' . $b['accom_num'] ][ $night ] = true;
				}
			}
		}
		return $taken;
	}
}
