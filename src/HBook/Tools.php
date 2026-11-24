<?php
/**
 * Owner tools that touch HBook rows the plugin doesn't own (plan 10). Each one is clicked by
 * the owner, keeps an undo list, and writes in one transaction.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Db;
use HostelMate\HBook\Core\State;

defined( 'ABSPATH' ) || exit;

final class Tools {

	/**
	 * Future, active iCal copies (bookings imported from another channel's calendar), by origin.
	 *
	 * @return array<string, int>
	 */
	public static function ical_copies(): array {
		global $wpdb;
		$resa  = Schema::table( 'resa' );
		$today = Clock::today( Clock::site_zone() );
		$out   = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT origin, COUNT(*) AS n FROM %i WHERE origin <> 'website' AND origin <> '' AND synchro_id <> '' AND status <> 'cancelled' AND check_out > %s GROUP BY origin", $resa, $today ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out[ (string) $row['origin'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * D8: cancels every future iCal copy, the way HBook cancels (status, then the blocks linked to
	 * it: database-actions.php:1642-1644). The ids and old statuses are kept for Undo.
	 *
	 * @return int How many copies were cancelled.
	 */
	public static function cancel_ical_copies(): int {
		global $wpdb;
		$resa  = Schema::table( 'resa' );
		$block = Schema::table( 'accom_blocked' );
		$today = Clock::today( Clock::site_zone() );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM %i WHERE origin <> 'website' AND origin <> '' AND synchro_id <> '' AND status <> 'cancelled' AND check_out > %s", $resa, $today ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( array() === $rows ) {
			return 0;
		}
		$undo = array();
		foreach ( $rows as $r ) {
			$undo[ (int) $r['id'] ] = (string) $r['status'];
		}
		$ids = array_keys( $undo );
		Db::transaction(
			static function () use ( $wpdb, $resa, $block, $ids ): void {
				$ok = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', updated_on = UTC_TIMESTAMP() WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND origin <> 'website'", array_merge( array( $resa ), $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$ok = false !== $ok && false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE linked_resa_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $block ), $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( ! $ok ) {
					throw new \RuntimeException( 'Cancelling iCal copies failed.' );
				}
			}
		);
		$previous = State::get( 'ical_undo', array() );
		State::set( 'ical_undo', ( is_array( $previous ) ? $previous : array() ) + $undo );
		return count( $undo );
	}

	/** How many cancelled copies Undo can bring back. */
	public static function undo_count(): int {
		$undo = State::get( 'ical_undo', array() );
		return is_array( $undo ) ? count( $undo ) : 0;
	}

	/**
	 * Undo of D8: puts back the old status of copies that are still cancelled. (Preparation blocks
	 * HBook had linked to them don't come back; HBook makes those only when a booking is created.)
	 *
	 * @return int How many came back.
	 */
	public static function undo_ical_cancel(): int {
		global $wpdb;
		$undo = State::get( 'ical_undo', array() );
		if ( ! is_array( $undo ) || array() === $undo ) {
			return 0;
		}
		$resa = Schema::table( 'resa' );
		$back = 0;
		Db::transaction(
			static function () use ( $wpdb, $resa, $undo, &$back ): void {
				foreach ( $undo as $id => $status ) {
					$n = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s WHERE id = %d AND status = 'cancelled' AND origin <> 'website'", $resa, (string) $status, (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( false === $n ) {
						throw new \RuntimeException( 'Undo failed.' );
					}
					$back += (int) $n;
				}
			}
		);
		State::set( 'ical_undo', array() );
		return $back;
	}

	/**
	 * D9: HBook's "price starting at" for matched rooms = the lowest planned price in the next
	 * Config::STARTING_PRICE_DAYS nights. The first time, the old value is kept for Remove.
	 *
	 * @param array<int, array<string, string>> $prices Planned prices: accommodation → night → amount.
	 * @param list<int>                         $matched Matched accommodation ids.
	 * @param list<string>                      $nights  Horizon nights.
	 */
	public static function update_starting_prices( array $prices, array $matched, array $nights ): void {
		$window = array_slice( $nights, 0, Config::STARTING_PRICE_DAYS );
		$kept   = array_flip( Owned::ids( Owned::STARTING_PRICE ) );
		foreach ( $matched as $accom ) {
			$amounts = array_filter( array_map( static fn( string $n ) => $prices[ $accom ][ $n ] ?? null, $window ) );
			if ( array() === $amounts ) {
				continue;
			}
			$value = \HostelMate\HBook\Core\Money::to_decimal( min( array_map( array( \HostelMate\HBook\Core\Money::class, 'to_minor' ), $amounts ) ) );
			if ( ! isset( $kept[ $accom ] ) ) {
				self::keep_starting_price( $accom );
			}
			update_post_meta( $accom, 'accom_starting_price', $value );
		}
	}

	/** Remove / uninstall: puts back every starting price the plugin changed. */
	public static function restore_starting_prices(): void {
		global $wpdb;
		$owned = Db::table( Config::TABLE_OWNED );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT hbook_id, note FROM %i WHERE kind = %s', $owned, Owned::STARTING_PRICE ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( null === $row['note'] ) {
				delete_post_meta( (int) $row['hbook_id'], 'accom_starting_price' );
			} else {
				update_post_meta( (int) $row['hbook_id'], 'accom_starting_price', (string) $row['note'] );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE kind = %s', $owned, Owned::STARTING_PRICE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Lists the current starting price before the plugin changes it.
	 *
	 * @param int $accom Accommodation id.
	 */
	private static function keep_starting_price( int $accom ): void {
		global $wpdb;
		$old = metadata_exists( 'post', $accom, 'accom_starting_price' ) ? (string) get_post_meta( $accom, 'accom_starting_price', true ) : null;
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			Db::table( Config::TABLE_OWNED ),
			array(
				'kind'       => Owned::STARTING_PRICE,
				'hbook_id'   => $accom,
				'note'       => $old,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not keep the old starting price.' );
		}
	}
}
