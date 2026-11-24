<?php
/**
 * Runs the operations for one booking against HostelMate, in order, updating what was
 * sent after each success (plan 7.3, 7.4). Stops at the first failure.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Ids;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Money;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\HostelMate\Result;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Sent from \HostelMate\HBook\Types
 * @phpstan-import-type Op from \HostelMate\HBook\Types
 * @phpstan-import-type Guest from \HostelMate\HBook\Types
 */
final class BookingSender {

	// How a send ended.
	public const DONE     = 'done';     // Every operation went through.
	public const RETRY    = 'retry';    // Server or network: try again later.
	public const REFUSED  = 'refused';  // HostelMate said no (bed sold elsewhere): wait for a change in HBook.
	public const INVALID  = 'invalid';  // HostelMate refused the data: wait for a change in HBook.
	public const STOP_ALL = 'stop_all'; // Key not accepted: stop every booking.
	public const STOP_RUN = 'stop_run'; // Rate limited: stop this run.

	/**
	 * @param int      $resa_id HBook reservation id (for logs).
	 * @param Sent     $sent    What HostelMate had before.
	 * @param list<Op> $ops     From BookingPlanner.
	 * @return array{sent: Sent, end: string, code: string}
	 */
	public static function send( int $resa_id, array $sent, array $ops ): array {
		$hm_payments = null;
		foreach ( $ops as $op ) {
			$data = $op['data'];
			switch ( $op['op'] ) {
				case BookingPlanner::CREATE:
					$result = self::create( $sent, $data );
					break;
				case BookingPlanner::FIX_NIGHTS:
					$nights = self::nights_of( $data );
					$result = Client::patch_booking( $sent['uuid'], array( 'booking' => array( 'dates' => self::dates( $nights ) ) ), self::key( $sent, 'nights', $nights ) );
					if ( $result->is_ok() ) {
						$sent['nights'] = $nights;
					}
					break;
				case BookingPlanner::SET_BED:
					$bed    = self::str( $data, 'bed' );
					$result = Client::patch_booking( $sent['uuid'], array( 'bed' => $bed ), self::key( $sent, 'bed', $bed ) );
					if ( Result::CONFLICT === $result->kind ) {
						// Bed taken in HostelMate: HostelMate's own bed (same room) stays, and that is logged.
						Log::write( Config::LOG_LEVEL_WARN, Messages::BOOKING_BED_KEPT, $resa_id );
						$result = Result::ok( array() );
					}
					if ( $result->is_ok() ) {
						$sent['bed']  = $bed;
						$sent['room'] = self::str( $data, 'room' );
					}
					break;
				case BookingPlanner::SET_ROOM:
					$room   = self::str( $data, 'room' );
					$result = Client::patch_booking( $sent['uuid'], array( 'room' => $room ), self::key( $sent, 'room', $room ) );
					if ( $result->is_ok() ) {
						$sent['room'] = $room;
						$sent['bed']  = '';
					}
					break;
				case BookingPlanner::SET_STATUS:
					$status = self::str( $data, 'status' );
					$result = Client::set_status( $sent['uuid'], $status, self::key( $sent, 'status', $status ) );
					if ( $result->is_ok() ) {
						$sent['status'] = $status;
						Log::write( Config::LOG_LEVEL_INFO, StatusRules::CANCELLED === $status ? Messages::BOOKING_CANCELLED : Messages::BOOKING_UPDATED, $resa_id, null, $status );
					}
					break;
				case BookingPlanner::ADD_PAYMENT:
					$hm_payments = $hm_payments ?? Client::payments( $sent['uuid'] );
					$result      = $hm_payments->is_ok() ? self::add_payment( $sent, $data, (array) $hm_payments->data ) : $hm_payments;
					if ( $result->is_ok() ) {
						$sent['payments'][ self::str( $data, 'ref' ) ] = array(
							'hm_id'  => is_array( $result->data ) && is_string( $result->data['id'] ?? null ) ? $result->data['id'] : '',
							'amount' => self::int( $data, 'amount' ),
						);
					}
					break;
				case BookingPlanner::PATCH_PAYMENT:
					$amount = self::int( $data, 'amount' );
					$result = Client::patch_payment( self::str( $data, 'hm_id' ), array( 'amount' => Money::to_decimal( $amount ) ), self::key( $sent, 'payment', self::str( $data, 'ref' ), $amount ) );
					if ( $result->is_ok() ) {
						$sent['payments'][ self::str( $data, 'ref' ) ] = array(
							'hm_id'  => self::str( $data, 'hm_id' ),
							'amount' => $amount,
						);
					}
					break;
				case BookingPlanner::PATCH_GUEST:
					$hash   = self::str( $data, 'hash' );
					$result = Client::patch_guest( $sent['guest_id'], array( 'guest' => self::guest_body( self::guest_of( $data ) ) ), self::key( $sent, 'guest', $hash ) );
					if ( $result->is_ok() ) {
						$sent['guest_hash'] = $hash;
					}
					break;
				default:
					$result = Result::failed( Result::INVALID, 'unknown_op' );
			}
			if ( ! $result->is_ok() ) {
				return array(
					'sent' => $sent,
					'end'  => self::end_of( $result ),
					'code' => $op['op'] . ':' . $result->kind . ':' . $result->code,
				);
			}
		}
		return array(
			'sent' => $sent,
			'end'  => self::DONE,
			'code' => '',
		);
	}

	/**
	 * POST /bookings, then the nights fix when amounts differ (B1: create stores night 1's amount on
	 * every night). A booking_id_conflict means an earlier try got through: the booking is adopted.
	 *
	 * @param Sent                 $sent Updated in place.
	 * @param array<string, mixed> $data Op data.
	 */
	private static function create( array &$sent, array $data ): Result {
		$nights = self::nights_of( $data );
		$room   = self::str( $data, 'room' );
		$guest  = self::guest_of( $data );
		$body   = array(
			'booking' => array(
				'id'    => $sent['uuid'],
				'dates' => self::dates( $nights ),
			),
			'room'    => $room,
			'guest'   => self::guest_body( $guest ),
			'notes'   => mb_substr( self::str( $data, 'notes' ), 0, Config::HM_NOTES_MAX ),
			'channel' => self::str( $data, 'channel' ),
		);
		$result = Client::create_booking( $body, self::key( $sent, 'create', $body ) );
		if ( Result::CONFLICT === $result->kind && 'booking_id_conflict' === $result->code ) {
			$found = Client::booking( $sent['uuid'] );
			if ( ! $found->is_ok() ) {
				return $found;
			}
			$sent['created']  = true;
			$sent['guest_id'] = self::str( (array) $found->data, 'guest_id' );
			$sent['status']   = self::str( (array) $found->data, 'status' );
			$sent['room']     = $room;
			$sent['nights']   = array(); // Unknown: the nights fix follows on the next pass.
			return Result::ok( array() );
		}
		if ( ! $result->is_ok() ) {
			return $result;
		}
		$sent['created']    = true;
		$sent['guest_id']   = self::str( (array) $result->data, 'guest_id' );
		$sent['status']     = StatusRules::RESERVED;
		$sent['room']       = $room;
		$sent['bed']        = '';
		$sent['guest_hash'] = BookingPlanner::guest_hash( $guest );
		Log::write( Config::LOG_LEVEL_INFO, Messages::BOOKING_SENT, null, $room );

		if ( count( array_unique( $nights ) ) <= 1 ) {
			$sent['nights'] = $nights;
			return $result;
		}
		$fix            = Client::patch_booking( $sent['uuid'], array( 'booking' => array( 'dates' => self::dates( $nights ) ) ), self::key( $sent, 'nights', $nights ) );
		$sent['nights'] = $fix->is_ok() ? $nights : array(); // On failure the planner asks for the fix again.
		return Result::ok( array() );
	}

	/**
	 * One payment, unless HostelMate already has it under our name (B4: a retry could otherwise save it twice).
	 *
	 * @param Sent                 $sent     The booking.
	 * @param array<string, mixed> $data     Op data.
	 * @param array<mixed>         $existing HostelMate's payments for this booking.
	 */
	private static function add_payment( array $sent, array $data, array $existing ): Result {
		$name = self::str( $data, 'name' );
		foreach ( $existing as $p ) {
			if ( is_array( $p ) && ( $p['name'] ?? '' ) === $name && is_string( $p['id'] ?? null ) ) {
				return Result::ok( array( 'id' => $p['id'] ) );
			}
		}
		$amount = self::int( $data, 'amount' );
		return Client::create_payment(
			array(
				'bid'           => $sent['uuid'],
				'amount'        => Money::to_decimal( $amount ),
				'paymentMethod' => self::str( $data, 'method' ),
				'type'          => self::str( $data, 'type' ),
				'name'          => mb_substr( $name, 0, Config::HM_PAYMENT_NAME_MAX ),
			),
			self::key( $sent, 'payment', self::str( $data, 'ref' ), $amount )
		);
	}

	/**
	 * The guest as HostelMate takes it (empty phone/country left out).
	 *
	 * @param Guest $g Guest.
	 * @return array<string, string>
	 */
	private static function guest_body( array $g ): array {
		return array_filter(
			array(
				'first_name'   => $g['first_name'],
				'last_name'    => $g['last_name'],
				'email'        => $g['email'],
				'phone'        => $g['phone'],
				'country_code' => $g['country'],
			),
			static fn( string $v ): bool => '' !== $v
		);
	}

	/**
	 * Nights → HostelMate's [{date, amount}].
	 *
	 * @param array<string, int> $nights Date → amount.
	 * @return list<array{date: string, amount: int}>
	 */
	private static function dates( array $nights ): array {
		$out = array();
		foreach ( $nights as $date => $amount ) {
			$out[] = array(
				'date'   => (string) $date,
				'amount' => $amount,
			);
		}
		return $out;
	}

	/**
	 * Same change → same Idempotency-Key, so a retry replays safely.
	 *
	 * @param Sent  $sent  The booking.
	 * @param mixed ...$parts What the change is.
	 */
	private static function key( array $sent, ...$parts ): string {
		// The state before the change is part of the key: A→B→A within HostelMate's 1-hour replay window
		// is three different keys, so the last change is applied, not replayed.
		$before = $sent;
		unset( $before['blocked'] );
		return Ids::idempotency_key( $sent['uuid'], (string) wp_json_encode( $parts ), (string) wp_json_encode( $before ) );
	}

	/**
	 * A text field of op (or answer) data.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Field.
	 */
	private static function str( array $data, string $key ): string {
		return is_scalar( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';
	}

	/**
	 * An integer field of op data.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Field.
	 */
	private static function int( array $data, string $key ): int {
		return is_int( $data[ $key ] ?? null ) ? $data[ $key ] : 0;
	}

	/**
	 * The nights of op data, date → amount.
	 *
	 * @param array<mixed> $data Op data.
	 * @return array<string, int>
	 */
	private static function nights_of( array $data ): array {
		$out = array();
		foreach ( is_array( $data['nights'] ?? null ) ? $data['nights'] : array() as $date => $amount ) {
			if ( is_int( $amount ) ) {
				$out[ (string) $date ] = $amount;
			}
		}
		return $out;
	}

	/**
	 * The guest of op data.
	 *
	 * @param array<mixed> $data Op data.
	 * @return Guest
	 */
	private static function guest_of( array $data ): array {
		$g = is_array( $data['guest'] ?? null ) ? $data['guest'] : array();
		return array(
			'first_name' => self::str( $g, 'first_name' ),
			'last_name'  => self::str( $g, 'last_name' ),
			'email'      => self::str( $g, 'email' ),
			'phone'      => self::str( $g, 'phone' ),
			'country'    => self::str( $g, 'country' ),
		);
	}

	/**
	 * How a failed result ends the send.
	 *
	 * @param Result $r The failure.
	 */
	private static function end_of( Result $r ): string {
		return match ( $r->kind ) {
			Result::UNAUTHORIZED, Result::FORBIDDEN => self::STOP_ALL,
			Result::RATE_LIMITED                    => self::STOP_RUN,
			Result::CONFLICT                        => self::REFUSED,
			Result::INVALID, Result::NOT_FOUND      => self::INVALID,
			default                                 => self::RETRY,
		};
	}
}
