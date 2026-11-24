<?php
/**
 * The plugin's record of what it sent to HostelMate for each HBook booking (table
 * hostelmate_bookings). Ids, states and hashes only: no guest data. Rows are claimed
 * with one atomic UPDATE so two runs can never send the same change.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Sync\BookingPlanner;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Sent from \HostelMate\HBook\Types
 */
final class BookingStore {

	public const SYNCED  = 'synced';
	public const PENDING = 'pending';
	public const FAILED  = 'failed';
	public const REFUSED = 'refused';

	/**
	 * Every row, by HBook reservation id.
	 *
	 * @return array<int, array{sent: Sent, state: string, attempts: int, next_try: int, code: string, since: int, failing: int}>
	 */
	public static function all(): array {
		global $wpdb;
		$table = Db::table( Config::TABLE_BOOKINGS );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT hbook_resa_id, hm_booking_id, sent_state, state, attempts, next_try_at, last_code, updated_at, failing_since FROM `{$table}`", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Config only.
			$out[ (int) $row['hbook_resa_id'] ] = array(
				'sent'     => self::decode( (string) $row['sent_state'], (string) $row['hm_booking_id'] ),
				'state'    => (string) $row['state'],
				'attempts' => (int) $row['attempts'],
				'next_try' => null === $row['next_try_at'] ? 0 : (int) strtotime( $row['next_try_at'] . ' UTC' ),
				'code'     => (string) $row['last_code'],
				'since'    => (int) strtotime( $row['updated_at'] . ' UTC' ),
				'failing'  => null === $row['failing_since'] ? 0 : (int) strtotime( $row['failing_since'] . ' UTC' ),
			);
		}
		return $out;
	}

	/**
	 * HBook reservation ids the plugin has created in HostelMate.
	 *
	 * @return list<int>
	 */
	public static function created_ids(): array {
		$out = array();
		foreach ( self::all() as $id => $row ) {
			if ( true === ( $row['sent']['created'] ?? false ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Claims a booking for this run (creating its row with a fresh HostelMate id if needed).
	 * Returns the claim token, or '' when another run holds it.
	 *
	 * @param int $resa_id HBook reservation id.
	 */
	public static function claim( int $resa_id ): string {
		global $wpdb;
		$table = Db::table( Config::TABLE_BOOKINGS );
		$uuid  = Ids::uuid4();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$table}` (hbook_resa_id, hm_booking_id, sent_state, state, attempts, updated_at) VALUES (%d, %s, %s, %s, 0, UTC_TIMESTAMP())", $resa_id, $uuid, wp_json_encode( BookingPlanner::nothing_sent( $uuid ) ), self::PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Config only.
		$token = Ids::token();
		$got   = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET claim_token = %s, claimed_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND) WHERE hbook_resa_id = %d AND (claimed_until IS NULL OR claimed_until < UTC_TIMESTAMP())", $token, Config::BOOKING_CLAIM_SECONDS, $resa_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Config only.
		return 1 === $got ? $token : '';
	}

	/**
	 * Saves the result of a run and releases the claim.
	 *
	 * @param int    $resa_id  HBook reservation id.
	 * @param string $token    Claim token.
	 * @param Sent   $sent     What HostelMate now has.
	 * @param string $state    SYNCED, PENDING, FAILED or REFUSED.
	 * @param int    $attempts Failed attempts in a row.
	 * @param int    $next_try Unix time before which it isn't retried (0 = any time).
	 * @param string $code     Last result code ('' when fine).
	 */
	public static function save( int $resa_id, string $token, array $sent, string $state, int $attempts, int $next_try, string $code ): void {
		global $wpdb;
		$table = Db::table( Config::TABLE_BOOKINGS );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE `{$table}` SET sent_state = %s, state = %s, attempts = %d, next_try_at = %s, last_code = %s, claim_token = NULL, claimed_until = NULL, updated_at = UTC_TIMESTAMP(), failing_since = IF( %s, COALESCE( failing_since, UTC_TIMESTAMP() ), NULL ) WHERE hbook_resa_id = %d AND claim_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from Config only.
				wp_json_encode( $sent ),
				$state,
				$attempts,
				0 === $next_try ? null : gmdate( 'Y-m-d H:i:s', $next_try ),
				substr( $code, 0, 48 ),
				self::PENDING === $state && '' !== $code ? 1 : 0, // Still failing: keep the first failure's time; anything else clears it.
				$resa_id,
				$token
			)
		);
	}

	/**
	 * Stored JSON → Sent, with every field checked (anything odd falls back to "nothing sent").
	 *
	 * @param string $json Stored sent_state.
	 * @param string $uuid The row's HostelMate id.
	 * @return Sent
	 */
	private static function decode( string $json, string $uuid ): array {
		$base = BookingPlanner::nothing_sent( Validate::is_uuid( $uuid ) ? strtolower( $uuid ) : Ids::uuid4() );
		$d    = json_decode( $json, true );
		if ( ! is_array( $d ) ) {
			return $base;
		}
		$nights = array();
		foreach ( is_array( $d['nights'] ?? null ) ? $d['nights'] : array() as $date => $amount ) {
			if ( Validate::is_date( (string) $date ) && is_int( $amount ) ) {
				$nights[ (string) $date ] = $amount;
			}
		}
		$payments = array();
		foreach ( is_array( $d['payments'] ?? null ) ? $d['payments'] : array() as $ref => $p ) {
			if ( is_array( $p ) && Validate::is_uuid( $p['hm_id'] ?? null ) && is_int( $p['amount'] ?? null ) ) {
				$payments[ (string) $ref ] = array(
					'hm_id'  => $p['hm_id'],
					'amount' => $p['amount'],
				);
			}
		}
		$str = static fn( string $k ): string => is_string( $d[ $k ] ?? null ) ? $d[ $k ] : '';
		return array(
			'uuid'       => $base['uuid'],
			'created'    => true === ( $d['created'] ?? false ),
			'guest_id'   => Validate::is_uuid( $d['guest_id'] ?? null ) ? $str( 'guest_id' ) : '',
			'status'     => $str( 'status' ),
			'room'       => Validate::is_uuid( $d['room'] ?? null ) ? $str( 'room' ) : '',
			'bed'        => Validate::is_uuid( $d['bed'] ?? null ) ? $str( 'bed' ) : '',
			'nights'     => $nights,
			'guest_hash' => $str( 'guest_hash' ),
			'payments'   => $payments,
			'blocked'    => $str( 'blocked' ),
		);
	}
}
