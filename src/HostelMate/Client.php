<?php
/**
 * Every HostelMate call the plugin makes, one method each. Nothing else builds a
 * request or reads an answer.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HostelMate;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Http;
use HostelMate\HBook\Core\Validate;

defined( 'ABSPATH' ) || exit;

final class Client {

	/** The property the key belongs to, with its rooms and beds. */
	public static function property(): Result {
		return Result::from_http(
			Http::call( 'GET', 'property' ),
			static fn( array $data ): ?array => Schemas::property( $data )
		);
	}

	/**
	 * Prices and availability for every room, for the nights $start..$end (both included,
	 * at most Config::RATES_MAX_NIGHTS).
	 *
	 * @param string $start First night, YYYY-MM-DD.
	 * @param string $end   Last night, YYYY-MM-DD.
	 */
	public static function rates( string $start, string $end ): Result {
		if ( ! Validate::is_date( $start ) || ! Validate::is_date( $end ) ) {
			return Result::failed( Result::INVALID, 'bad_dates' );
		}
		$nights = Clock::days_between( $start, $end ) + 1;
		if ( $nights < 1 || $nights > Config::RATES_MAX_NIGHTS ) {
			return Result::failed( Result::INVALID, 'bad_range' );
		}
		return Result::from_http(
			Http::call(
				'GET',
				'rates',
				array(
					'start_date' => $start,
					'end_date'   => $end,
				)
			),
			static fn( array $data ): ?array => Schemas::rates( $data, $start, $end )
		);
	}

	/**
	 * Creates a booking with our own id (retry-safe: a second create is a 409 booking_id_conflict).
	 *
	 * @param array<string, mixed> $body Request body.
	 * @param string               $key  Idempotency-Key.
	 */
	public static function create_booking( array $body, string $key ): Result {
		return Result::from_http( Http::call( 'POST', 'bookings', array(), $body, $key ), static fn( array $d ): ?array => Schemas::booking_created( $d ) );
	}

	/**
	 * Changes nights, room or bed of a booking.
	 *
	 * @param string               $id   HostelMate booking id.
	 * @param array<string, mixed> $body Request body.
	 * @param string               $key  Idempotency-Key.
	 */
	public static function patch_booking( string $id, array $body, string $key ): Result {
		return self::uuid_or_invalid( $id ) ?? Result::from_http( Http::call( 'PATCH', 'bookings/' . $id, array(), $body, $key ), static fn( array $d ): array => Schemas::any( $d ) );
	}

	/**
	 * Sets a booking's status (cancel included; the plugin never deletes).
	 *
	 * @param string $id     HostelMate booking id.
	 * @param string $status reserved, confirmed or cancelled.
	 * @param string $key    Idempotency-Key.
	 */
	public static function set_status( string $id, string $status, string $key ): Result {
		return self::uuid_or_invalid( $id ) ?? Result::from_http( Http::call( 'POST', 'bookings/' . $id . '/update', array(), array( 'status' => $status ), $key ), static fn( array $d ): array => Schemas::any( $d ) );
	}

	/**
	 * Reads one booking (to adopt it after a booking_id_conflict).
	 *
	 * @param string $id HostelMate booking id.
	 */
	public static function booking( string $id ): Result {
		return self::uuid_or_invalid( $id ) ?? Result::from_http( Http::call( 'GET', 'bookings/' . $id ), static fn( array $d ): ?array => Schemas::booking( $d ) );
	}

	/**
	 * A booking's payments (to avoid sending one twice).
	 *
	 * @param string $id HostelMate booking id.
	 */
	public static function payments( string $id ): Result {
		return self::uuid_or_invalid( $id ) ?? Result::from_http( Http::call( 'GET', 'payments', array( 'booking_id' => $id ) ), static fn( array $d ): ?array => Schemas::payments( $d ) );
	}

	/**
	 * Records a payment (no money moves).
	 *
	 * @param array<string, mixed> $body Request body.
	 * @param string               $key  Idempotency-Key.
	 */
	public static function create_payment( array $body, string $key ): Result {
		return Result::from_http( Http::call( 'POST', 'payments', array(), $body, $key ), static fn( array $d ): ?array => Schemas::payment_created( $d ) );
	}

	/**
	 * Changes a payment's amount (a refund that grew).
	 *
	 * @param string               $id   HostelMate payment id.
	 * @param array<string, mixed> $body Request body.
	 * @param string               $key  Idempotency-Key.
	 */
	public static function patch_payment( string $id, array $body, string $key ): Result {
		return self::uuid_or_invalid( $id ) ?? Result::from_http( Http::call( 'PATCH', 'payments/' . $id, array(), $body, $key ), static fn( array $d ): array => Schemas::any( $d ) );
	}

	/**
	 * Updates the guest's details.
	 *
	 * @param string               $id   HostelMate guest id.
	 * @param array<string, mixed> $body Request body.
	 * @param string               $key  Idempotency-Key.
	 */
	public static function patch_guest( string $id, array $body, string $key ): Result {
		return self::uuid_or_invalid( $id ) ?? Result::from_http( Http::call( 'PATCH', 'guests/' . $id, array(), $body, $key ), static fn( array $d ): array => Schemas::any( $d ) );
	}

	/**
	 * Can this stay be booked right now (stay rules, closures, cutoff and window applied)? One bed per booking, so guests=1.
	 *
	 * @param string $check_in  First night.
	 * @param string $check_out Check-out day.
	 * @param int    $timeout   Seconds.
	 */
	public static function availability( string $check_in, string $check_out, int $timeout ): Result {
		if ( ! Validate::is_date( $check_in ) || ! Validate::is_date( $check_out ) || $check_out <= $check_in ) {
			return Result::failed( Result::INVALID, 'bad_dates' );
		}
		return Result::from_http(
			Http::call(
				'GET',
				'availability',
				array(
					'check_in'  => $check_in,
					'check_out' => $check_out,
					'guests'    => 1,
				),
				null,
				'',
				$timeout
			),
			static fn( array $d ): ?array => Schemas::availability( $d, \HostelMate\HBook\Core\Clock::days_between( $check_in, $check_out ) )
		);
	}

	/**
	 * HostelMate's public exchange rates: the newest rate per currency (per 1 USD) and when HostelMate last
	 * refreshed them. Used only while the two currencies differ (Sync\Fx).
	 */
	public static function currency_rates(): Result {
		return Result::from_http( Http::rates_list(), static fn( array $d ): ?array => Schemas::currency_rates( $d ) );
	}

	/**
	 * Ids go into URL paths: anything but a UUID is refused before any call.
	 *
	 * @param string $id Id.
	 */
	private static function uuid_or_invalid( string $id ): ?Result {
		return Validate::is_uuid( $id ) ? null : Result::failed( Result::INVALID, 'bad_id' );
	}
}
