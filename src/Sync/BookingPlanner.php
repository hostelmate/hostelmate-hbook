<?php
/**
 * What HostelMate needs so it matches one HBook website booking (plan 7.3). Pure:
 * the booking now + what was last sent → the operations, in order.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Money;
use HostelMate\HBook\Core\Validate;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Booking from \HostelMate\HBook\Types
 * @phpstan-import-type Sent from \HostelMate\HBook\Types
 * @phpstan-import-type Op from \HostelMate\HBook\Types
 * @phpstan-import-type Guest from \HostelMate\HBook\Types
 */
final class BookingPlanner {

	public const CREATE        = 'create';
	public const FIX_NIGHTS    = 'fix_nights';
	public const SET_BED       = 'set_bed';
	public const SET_ROOM      = 'set_room';
	public const SET_STATUS    = 'set_status';
	public const ADD_PAYMENT   = 'add_payment';
	public const PATCH_PAYMENT = 'patch_payment';
	public const PATCH_GUEST   = 'patch_guest';

	/** Problems that stop a booking from being sent (shown as alerts). */
	public const PROBLEM_NO_EMAIL  = Messages::BOOKING_NO_EMAIL;
	public const PROBLEM_UNMATCHED = Messages::BOOKING_UNMATCHED;
	public const PROBLEM_TOO_LONG  = Messages::BOOKING_TOO_LONG;

	/**
	 * A fresh "nothing sent yet" record.
	 *
	 * @param string $uuid The HostelMate booking id to use.
	 * @return Sent
	 */
	public static function nothing_sent( string $uuid ): array {
		return array(
			'uuid'       => $uuid,
			'created'    => false,
			'guest_id'   => '',
			'status'     => '',
			'room'       => '',
			'bed'        => '',
			'nights'     => array(),
			'guest_hash' => '',
			'payments'   => array(),
			'blocked'    => '',
		);
	}

	/**
	 * @param Booking|null                     $booking   The booking now (null = deleted in HBook).
	 * @param Sent                             $sent      What HostelMate was last given.
	 * @param array{room: string, bed: string}|null $target Matched HostelMate room and bed (null = not matched).
	 * @param bool                             $paid_needs HBook's hb_resa_paid_has_confirmation.
	 * @param array<string, string>            $methods   HBook gateway → HostelMate payment method.
	 * @param string                           $today     Today: a stay that has already ended is never created (only kept up to date).
	 * @return array{ops: list<Op>, problem: string}
	 */
	public static function plan( ?array $booking, array $sent, ?array $target, bool $paid_needs, array $methods, string $today = '0000-00-00' ): array {
		$wanted = StatusRules::wanted( null === $booking ? null : $booking['status'], $paid_needs );
		$ops    = array();

		if ( StatusRules::RESERVED !== $wanted && StatusRules::CONFIRMED !== $wanted ) {
			if ( $sent['created'] && StatusRules::CANCELLED !== $sent['status'] ) {
				$ops[] = self::op( self::SET_STATUS, array( 'status' => StatusRules::CANCELLED ) );
			}
			if ( $sent['created'] && null !== $booking ) {
				$ops = array_merge( $ops, self::payment_ops( $booking, $sent, $methods ) );
			}
			return array(
				'ops'     => $ops,
				'problem' => '',
			);
		}
		if ( null === $booking || ( ! $sent['created'] && $booking['check_out'] <= $today ) ) {
			return array(
				'ops'     => array(),
				'problem' => '',
			);
		}
		if ( null === $target ) {
			return array(
				'ops'     => array(),
				'problem' => self::PROBLEM_UNMATCHED,
			);
		}
		$guest = self::guest( $booking['guest'] );
		if ( ! Validate::is_email( $guest['email'] ) ) {
			return array(
				'ops'     => array(),
				'problem' => self::PROBLEM_NO_EMAIL,
			);
		}
		$nights = self::nights( $booking );
		if ( array() === $nights || count( $nights ) > Config::HM_MAX_NIGHTS ) {
			return array(
				'ops'     => array(),
				'problem' => self::PROBLEM_TOO_LONG,
			);
		}

		if ( ! $sent['created'] ) {
			$ops[] = self::op(
				self::CREATE,
				array(
					'uuid'    => $sent['uuid'],
					'room'    => $target['room'],
					'nights'  => $nights,
					'guest'   => $guest,
					'notes'   => self::notes( $booking ),
					'channel' => Config::HM_CHANNEL,
				)
			);
			$sent  = array(
				'created'    => true,
				'room'       => $target['room'],
				'bed'        => '',
				'nights'     => $nights,
				'status'     => StatusRules::RESERVED,
				'guest_hash' => self::guest_hash( $guest ),
			) + $sent;
		}
		if ( $nights !== $sent['nights'] ) {
			$ops[] = self::op( self::FIX_NIGHTS, array( 'nights' => $nights ) );
		}
		if ( $target['room'] !== $sent['room'] || $target['bed'] !== $sent['bed'] ) {
			$ops[] = '' === $target['bed']
				? self::op( self::SET_ROOM, array( 'room' => $target['room'] ) )
				: self::op(
					self::SET_BED,
					array(
						'bed'  => $target['bed'],
						'room' => $target['room'],
					)
				);
		}
		if ( $wanted !== $sent['status'] ) {
			$ops[] = self::op( self::SET_STATUS, array( 'status' => $wanted ) );
		}
		if ( self::guest_hash( $guest ) !== $sent['guest_hash'] && '' !== $sent['guest_id'] ) {
			$ops[] = self::op(
				self::PATCH_GUEST,
				array(
					'guest' => $guest,
					'hash'  => self::guest_hash( $guest ),
				)
			);
		}
		return array(
			'ops'     => array_merge( $ops, self::payment_ops( $booking, $sent, $methods ) ),
			'problem' => '',
		);
	}

	/**
	 * The nights with their amounts: the HBook total spread evenly, remainder on the last night.
	 *
	 * @param Booking $booking The booking.
	 * @return array<string, int>
	 */
	public static function nights( array $booking ): array {
		if ( ! Validate::is_date( $booking['check_in'] ) || ! Validate::is_date( $booking['check_out'] ) || $booking['check_out'] <= $booking['check_in'] ) {
			return array();
		}
		$dates = Clock::nights( $booking['check_in'], $booking['check_out'] );
		if ( count( $dates ) > Config::HM_MAX_NIGHTS ) {
			return array_fill_keys( $dates, 0 );
		}
		return array_combine( $dates, Money::split( $booking['total'], count( $dates ) ) );
	}

	/**
	 * One payment per HBook entry, and one refund per refunded entry (patched if the refund grows).
	 *
	 * @param Booking               $booking The booking.
	 * @param Sent                  $sent    What was sent.
	 * @param array<string, string> $methods Gateway → method.
	 * @return list<Op>
	 */
	private static function payment_ops( array $booking, array $sent, array $methods ): array {
		$ops = array();
		foreach ( $booking['payments'] as $p ) {
			$method = $methods[ in_array( $p['gateway'], array( 'stripe', 'paypal' ), true ) ? $p['gateway'] : 'other' ] ?? 'Cash';
			$ref    = 'p' . $p['ref'];
			if ( $p['amount'] > 0 && ! isset( $sent['payments'][ $ref ] ) ) {
				$ops[] = self::op(
					self::ADD_PAYMENT,
					array(
						'ref'    => $ref,
						'amount' => $p['amount'],
						'type'   => 'income',
						'method' => $method,
						'name'   => self::payment_name( $booking, $ref ),
					)
				);
			}
			$refund = $ref . 'r';
			if ( $p['refunded'] > 0 && ! isset( $sent['payments'][ $refund ] ) ) {
				$ops[] = self::op(
					self::ADD_PAYMENT,
					array(
						'ref'    => $refund,
						'amount' => $p['refunded'],
						'type'   => 'outgoing',
						'method' => $method,
						'name'   => self::payment_name( $booking, $refund ),
					)
				);
			} elseif ( isset( $sent['payments'][ $refund ] ) && $sent['payments'][ $refund ]['amount'] !== $p['refunded'] ) {
				$ops[] = self::op(
					self::PATCH_PAYMENT,
					array(
						'ref'    => $refund,
						'hm_id'  => $sent['payments'][ $refund ]['hm_id'],
						'amount' => $p['refunded'],
					)
				);
			}
		}
		return $ops;
	}

	/**
	 * The name HostelMate keeps on a payment, used to find it again after a timeout.
	 *
	 * @param Booking $booking The booking.
	 * @param string  $ref     Payment reference.
	 */
	public static function payment_name( array $booking, string $ref ): string {
		return 'hbook:' . $booking['id'] . ':' . $ref;
	}

	/**
	 * Guest fields cut to HostelMate's limits; HostelMate needs a last name, so a single name gets "-".
	 *
	 * @param Guest $g Guest from HBook.
	 * @return Guest
	 */
	private static function guest( array $g ): array {
		$cut = static fn( string $v, int $max ): string => mb_substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v ) ), 0, $max );
		return array(
			'first_name' => '' !== $cut( $g['first_name'], Config::HM_GUEST_NAME_MAX ) ? $cut( $g['first_name'], Config::HM_GUEST_NAME_MAX ) : '-',
			'last_name'  => '' !== $cut( $g['last_name'], Config::HM_GUEST_NAME_MAX ) ? $cut( $g['last_name'], Config::HM_GUEST_NAME_MAX ) : '-',
			'email'      => strtolower( trim( $g['email'] ) ),
			'phone'      => $cut( $g['phone'], Config::HM_GUEST_PHONE_MAX ),
			'country'    => strtoupper( $cut( $g['country'], 3 ) ),
		);
	}

	/**
	 * A change marker for the guest fields (the plugin never stores the fields themselves).
	 *
	 * @param Guest $guest Guest.
	 */
	public static function guest_hash( array $guest ): string {
		return \HostelMate\HBook\Core\Ids::marker( $guest );
	}

	/**
	 * Notes on the HostelMate booking: the HBook number and the guest count, no free text.
	 *
	 * @param Booking $booking The booking.
	 */
	private static function notes( array $booking ): string {
		/* translators: 1: HBook booking number, 2: number of guests */
		return sprintf( __( 'Website booking (HBook #%1$s) · %2$d guests', 'hostelmate-hbook' ), $booking['number'], $booking['guests'] );
	}

	/**
	 * One operation.
	 *
	 * @param string               $op   One of the constants.
	 * @param array<string, mixed> $data Its data.
	 * @return Op
	 */
	private static function op( string $op, array $data ): array {
		return array(
			'op'   => $op,
			'data' => $data,
		);
	}
}
