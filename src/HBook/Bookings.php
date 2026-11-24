<?php
/**
 * HBook's website bookings, normalised for the booking loop (plan 7). Origin 'website'
 * covers the front end and the admin (front-end-ajax-actions.php:175); iCal copies are
 * never read here. A multi-room booking is one child row per room; its parent holds the
 * total and the payments, which are split by each child's price (remainder on the last).
 * Card data (payment_token, payment_info) is never read.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Booking from \HostelMate\HBook\Types
 * @phpstan-import-type BookingPayment from \HostelMate\HBook\Types
 * @phpstan-import-type Guest from \HostelMate\HBook\Types
 */
final class Bookings {

	/** HBook's origin for bookings made on the website (front-end-ajax-actions.php: 'origin' => 'website'). */
	public const ORIGIN = 'website';

	/**
	 * Website bookings with check-out on or after $since, by reservation id.
	 *
	 * @param string $since First check-out date to include.
	 * @return array<int, Booking>
	 */
	public static function website_since( string $since ): array {
		global $wpdb;
		$resa      = Schema::table( 'resa' );
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, parent_id, accom_id, accom_num, check_in, check_out, adults, children, price, status, customer_id, alphanum_id, payments_logs, paid, payment_gateway FROM `{$resa}` WHERE origin = %s AND check_out >= %s ORDER BY id", self::ORIGIN, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema only.
		$by_parent = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['parent_id'] > 0 ) {
				$by_parent[ (int) $r['parent_id'] ][] = $r;
			}
		}
		$parents   = self::parents( array_keys( $by_parent ) );
		$customers = self::customers( array_merge( array_column( $rows, 'customer_id' ), array_column( $parents, 'customer_id' ) ) );

		$out = array();
		foreach ( $rows as $r ) {
			$parent_id = (int) $r['parent_id'];
			if ( $parent_id > 0 && isset( $parents[ $parent_id ] ) ) {
				continue; // Handled with its siblings below.
			}
			$out[ (int) $r['id'] ] = self::normalise( $r, Money::to_minor( self::decimal( (string) $r['price'] ) ), self::payments( (string) $r['payments_logs'], (string) $r['paid'], (string) $r['payment_gateway'] ), $customers, (string) $r['alphanum_id'] );
		}
		foreach ( $by_parent as $parent_id => $children ) {
			$parent = $parents[ $parent_id ] ?? null;
			if ( null === $parent ) {
				continue;
			}
			$weights  = array_map( static fn( array $c ): int => max( 0, Money::to_minor( self::decimal( (string) $c['price'] ) ) ), $children );
			$totals   = Money::split_by_weights( max( 0, Money::to_minor( self::decimal( (string) $parent['price'] ) ) ), $weights );
			$payments = self::split_payments( self::payments( (string) $parent['payments_logs'], (string) $parent['paid'], (string) $parent['payment_gateway'] ), $weights );
			foreach ( $children as $i => $c ) {
				$c['customer_id']      = $parent['customer_id'];
				$out[ (int) $c['id'] ] = self::normalise( $c, $totals[ $i ], $payments[ $i ], $customers, (string) $parent['alphanum_id'] );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Which of these reservation ids still exist at all (any origin or status).
	 *
	 * @param list<int> $ids Reservation ids.
	 * @return list<int>
	 */
	public static function existing( array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$resa = Schema::table( 'resa' );
		$in   = implode( ',', array_map( 'intval', $ids ) );
		return array_values( array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM `{$resa}` WHERE id IN ({$in})" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema, ids are integers.
	}

	/**
	 * One booking in the plugin's shape.
	 *
	 * @param array<string, mixed>                 $r         hb_resa row.
	 * @param int                                  $total     Total in minor units.
	 * @param list<BookingPayment>                 $payments  Payment entries.
	 * @param array<int, Guest>                    $customers Customer id → guest fields.
	 * @param string                               $alphanum  HBook's booking number.
	 * @return Booking
	 */
	private static function normalise( array $r, int $total, array $payments, array $customers, string $alphanum ): array {
		return array(
			'id'        => (int) $r['id'],
			'accom_id'  => (int) $r['accom_id'],
			'accom_num' => (int) $r['accom_num'],
			'check_in'  => (string) $r['check_in'],
			'check_out' => (string) $r['check_out'],
			'guests'    => max( 1, (int) $r['adults'] + (int) $r['children'] ),
			'status'    => (string) $r['status'],
			'total'     => max( 0, $total ),
			'number'    => $alphanum,
			'guest'     => $customers[ (int) $r['customer_id'] ] ?? array(
				'first_name' => '',
				'last_name'  => '',
				'email'      => '',
				'phone'      => '',
				'country'    => '',
			),
			'payments'  => $payments,
		);
	}

	/**
	 * The money HBook holds for a booking, as signed entries in minor units: one per payments_logs
	 * entry (a refund is HBook's own negative entry, added by every refund path, admin-ajax-actions.php
	 * hb_resa_refund and hb_refund_resa_payment, or typed in by the owner), skipping entries HBook marks
	 * 'failed' (HBook leaves them out of what was paid, utils.php resa_total_paid). refunded_amount is
	 * not read: it is only a note on a charge, and HBook 2.2.1 overwrites it on a second partial refund
	 * (database-actions.php mark_resa_payment_refunded). A booking from before payment logs keeps its
	 * money in the `paid` column: then that column is one entry (ref "0"). When logs exist the column is
	 * already in them (HBook moved it there as an entry with no date), so it is not counted twice.
	 *
	 * @param string $json    payments_logs.
	 * @param string $paid    The `paid` column.
	 * @param string $gateway The `payment_gateway` column (for the old `paid` column).
	 * @return list<BookingPayment>
	 */
	public static function payments( string $json, string $paid, string $gateway ): array {
		$list = json_decode( $json, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $p ) {
			if ( ! is_array( $p ) || ! is_numeric( $p['id'] ?? null ) || ! is_numeric( $p['amount'] ?? null ) || isset( $p['failed'] ) ) {
				continue;
			}
			$amount = Money::to_minor( self::decimal( (string) $p['amount'] ) );
			if ( 0 !== $amount ) {
				$out[] = array(
					'ref'     => (string) (int) $p['id'],
					'amount'  => $amount,
					'gateway' => strtolower( is_string( $p['payment_method'] ?? null ) ? $p['payment_method'] : '' ),
				);
			}
		}
		$legacy = Money::to_minor( self::decimal( $paid ) );
		if ( ( ! is_array( $list ) || array() === $list ) && 0 !== $legacy ) {
			$out[] = array(
				'ref'     => '0',
				'amount'  => $legacy,
				'gateway' => strtolower( $gateway ),
			);
		}
		return $out;
	}

	/**
	 * A parent's payments split across its children by weight.
	 *
	 * @param list<BookingPayment> $payments Parent entries.
	 * @param list<int>            $weights  Child prices.
	 * @return list<list<BookingPayment>>
	 */
	private static function split_payments( array $payments, array $weights ): array {
		$out = array_fill( 0, count( $weights ), array() );
		foreach ( $payments as $p ) {
			$sign    = $p['amount'] < 0 ? -1 : 1;
			$amounts = Money::split_by_weights( $sign * $p['amount'], $weights );
			foreach ( array_keys( $weights ) as $i ) {
				if ( 0 !== $amounts[ $i ] ) {
					$out[ $i ][] = array(
						'ref'     => $p['ref'],
						'amount'  => $sign * $amounts[ $i ],
						'gateway' => $p['gateway'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Parent rows by id.
	 *
	 * @param list<int> $ids Parent ids.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parents( array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$table = Schema::table( 'parents_resa' );
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, price, customer_id, alphanum_id, payments_logs, paid, payment_gateway FROM `{$table}` WHERE id IN ({$in})", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema, ids are integers.
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Guest fields per customer id, from HBook's info JSON (only what HostelMate needs).
	 *
	 * @param array<int, mixed> $ids Customer ids.
	 * @return array<int, Guest>
	 */
	private static function customers( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table = Schema::table( 'customers' );
		$in    = implode( ',', $ids );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, info FROM `{$table}` WHERE id IN ({$in})", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema, ids are integers.
			$info                    = json_decode( (string) $row['info'], true );
			$info                    = is_array( $info ) ? $info : array();
			$get                     = static function ( string ...$keys ) use ( $info ): string {
				foreach ( $keys as $k ) {
					$v = is_scalar( $info[ $k ] ?? null ) ? trim( (string) $info[ $k ] ) : '';
					if ( '' !== $v ) {
						return $v;
					}
				}
				return '';
			};
			$out[ (int) $row['id'] ] = array(
				'first_name' => $get( 'first_name', 'firstname', 'name' ),
				'last_name'  => $get( 'last_name', 'lastname' ),
				'email'      => $get( 'email' ),
				'phone'      => $get( 'phone', 'telephone', 'tel' ),
				'country'    => $get( 'country_iso', 'country' ),
			);
		}
		return $out;
	}

	/**
	 * HBook stores decimal(14,2) and payment amounts as numbers: always two-decimal text here.
	 *
	 * @param string $value Number as text.
	 */
	private static function decimal( string $value ): string {
		return is_numeric( $value ) ? number_format( (float) $value, 2, '.', '' ) : '0.00';
	}
}
