<?php
/**
 * One booking run, HBook → HostelMate (plan 7.1). Re-derives everything from HBook every
 * time, so a missed hook, a raw database edit or a deleted row is always caught.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\BookingStore;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Db;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\HBook\Bookings;
use HostelMate\HBook\HBook\Occupancy;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Booking from \HostelMate\HBook\Types
 * @phpstan-import-type Sent from \HostelMate\HBook\Types
 */
final class BookingRunner {

	/** True once a run was asked for in this request (per-request flag, never stored). */
	private static bool $soon = false;

	/** HBook hook callback: run at the end of this request, after the page is sent. */
	public static function soon(): void {
		if ( self::$soon || Config::MODE_LIVE !== Settings::mode() ) {
			return;
		}
		self::$soon = true;
		add_action(
			'shutdown',
			\HostelMate\HBook\Core\Guard::wrap(
				static function (): void {
					if ( function_exists( 'fastcgi_finish_request' ) ) {
						fastcgi_finish_request();
					} elseif ( function_exists( 'litespeed_finish_request' ) ) {
						litespeed_finish_request();
					}
					self::run( Config::BOOKING_SOON_SECONDS );
					\HostelMate\HBook\Core\Alerts::send_due(); // This run ends the request: its alerts go out now.
				}
			),
			PHP_INT_MAX
		);
	}

	/**
	 * One run. Returns how many bookings were sent something.
	 *
	 * @param int $budget Seconds this run may take.
	 */
	public static function run( int $budget = Config::BOOKING_RUN_SECONDS ): int {
		if ( Config::MODE_LIVE !== Settings::mode() || ! Db::lock( Config::LOCK_BOOKINGS ) ) {
			return 0;
		}
		try {
			return self::run_locked( time() + max( 1, $budget ) );
		} finally {
			Db::unlock( Config::LOCK_BOOKINGS );
		}
	}

	/**
	 * Everything after the lock.
	 *
	 * @param int $deadline Unix time to stop starting new bookings.
	 */
	private static function run_locked( int $deadline ): int {
		$today    = Clock::today( Clock::site_zone() );
		$bookings = Bookings::website_since( Clock::add_days( $today, -Config::BOOKING_LOOKBACK_DAYS ) );
		$store    = BookingStore::all();
		$targets  = self::targets();
		$methods  = Settings::all()['payment_methods'];
		$paid     = Occupancy::paid_needs_confirmation();

		// Rows the plugin sent whose HBook row is gone entirely: deleted in HBook.
		$sent_ids = array_keys( array_filter( $store, static fn( array $r ): bool => $r['sent']['created'] ) );
		$existing = array_flip( Bookings::existing( $sent_ids ) );
		$ids      = array_keys( $bookings );
		foreach ( $sent_ids as $id ) {
			if ( ! isset( $bookings[ $id ] ) && ! isset( $existing[ $id ] ) ) {
				$ids[] = $id;
			}
		}

		$touched = 0;
		foreach ( $ids as $id ) {
			if ( time() >= $deadline ) {
				break;
			}
			$row     = $store[ $id ] ?? null;
			$booking = $bookings[ $id ] ?? null;
			if ( null !== $row && $row['next_try'] > time() ) {
				continue;
			}
			$fingerprint = \HostelMate\HBook\Core\Ids::marker( $booking );
			if ( null !== $row && in_array( $row['state'], array( BookingStore::REFUSED, BookingStore::FAILED ), true ) && $row['sent']['blocked'] === $fingerprint ) {
				continue; // Waiting for the booking to change in HBook.
			}
			$sent = null === $row ? BookingPlanner::nothing_sent( \HostelMate\HBook\Core\Ids::uuid4() ) : $row['sent'];
			$plan = BookingPlanner::plan( $booking, $sent, self::target( $targets, $booking ), $paid, $methods, $today );
			if ( array() === $plan['ops'] && '' === $plan['problem'] ) {
				if ( null !== $row && BookingStore::SYNCED !== $row['state'] ) {
					self::save( $id, $sent, BookingStore::SYNCED, 0, 0, '', $booking );
				}
				continue;
			}
			if ( '' !== $plan['problem'] ) {
				$sent['blocked'] = $fingerprint;
				self::save( $id, $sent, BookingStore::FAILED, ( $row['attempts'] ?? 0 ) + 1, 0, $plan['problem'], $booking );
				continue;
			}

			$token = BookingStore::claim( $id );
			if ( '' === $token ) {
				continue;
			}
			// The claim created the row if it was new: use its id, not the one planned with.
			$fresh = BookingStore::all()[ $id ]['sent'];
			if ( ! $fresh['created'] && $fresh['uuid'] !== $sent['uuid'] ) {
				$sent = $fresh;
				$plan = BookingPlanner::plan( $booking, $sent, self::target( $targets, $booking ), $paid, $methods, $today );
			}
			$result = BookingSender::send( $id, $sent, $plan['ops'] );
			++$touched;
			self::finish( $id, $token, $result, $row, $booking, $fingerprint );
			if ( in_array( $result['end'], array( BookingSender::STOP_ALL, BookingSender::STOP_RUN ), true ) ) {
				break;
			}
		}
		return $touched;
	}

	/**
	 * Saves how one booking's send ended, with retry timing and alerts.
	 *
	 * @param int                                          $id          Reservation id.
	 * @param string                                       $token       Claim token.
	 * @param array{sent: Sent, end: string, code: string} $result BookingSender result.
	 * @param array<string, mixed>|null                    $row         Previous row.
	 * @param Booking|null                                 $booking     The booking.
	 * @param string                                       $fingerprint Booking fingerprint.
	 */
	private static function finish( int $id, string $token, array $result, ?array $row, ?array $booking, string $fingerprint ): void {
		$sent     = $result['sent'];
		$ref      = self::ref( $booking, $id );
		$attempts = (int) ( $row['attempts'] ?? 0 );
		switch ( $result['end'] ) {
			case BookingSender::DONE:
				$sent['blocked'] = '';
				BookingStore::save( $id, $token, $sent, BookingStore::SYNCED, 0, 0, '' );
				Alerts::resolve( Messages::BOOKING_REFUSED, $ref );
				Alerts::resolve( Messages::BOOKING_FAILED, $ref );
				return;
			case BookingSender::REFUSED:
				$sent['blocked'] = $fingerprint;
				BookingStore::save( $id, $token, $sent, BookingStore::REFUSED, $attempts + 1, 0, $result['code'] );
				Alerts::open( Messages::BOOKING_REFUSED, Config::ALERT_LEVEL_RED, $ref );
				Log::write( Config::LOG_LEVEL_ERROR, Messages::BOOKING_REFUSED, $id, null, $result['code'] );
				return;
			case BookingSender::INVALID:
				$sent['blocked'] = $fingerprint;
				BookingStore::save( $id, $token, $sent, BookingStore::FAILED, $attempts + 1, 0, $result['code'] );
				Alerts::open( Messages::BOOKING_FAILED, Config::ALERT_LEVEL_RED, $ref );
				Log::write( Config::LOG_LEVEL_ERROR, Messages::BOOKING_FAILED, $id, null, $result['code'] );
				return;
			case BookingSender::STOP_ALL:
				BookingStore::save( $id, $token, $sent, BookingStore::PENDING, $attempts, time() + 300, $result['code'] );
				Alerts::open( Messages::SYNC_KEY_REFUSED, Config::ALERT_LEVEL_RED );
				return;
			default: // RETRY, STOP_RUN.
				$waits = Config::BOOKING_RETRY_AFTER;
				$wait  = $waits[ min( $attempts, count( $waits ) - 1 ) ];
				BookingStore::save( $id, $token, $sent, BookingStore::PENDING, $attempts + 1, time() + $wait, $result['code'] );
				$since = (int) ( $row['since'] ?? time() );
				if ( time() - $since >= Config::BOOKING_ALERT_AFTER && $attempts > 0 ) {
					Alerts::open( Messages::BOOKING_FAILED, Config::ALERT_LEVEL_RED, $ref );
				}
				Log::write( Config::LOG_LEVEL_WARN, Messages::BOOKING_RETRY, $id, null, $result['code'] );
		}
	}

	/**
	 * Saves a row without sending anything (no claim needed: nothing is in flight).
	 *
	 * @param int                  $id       Reservation id.
	 * @param Sent                 $sent     Sent record.
	 * @param string               $state    BookingStore state.
	 * @param int                  $attempts Attempts.
	 * @param int                  $next_try Next try.
	 * @param string               $code     Code.
	 * @param Booking|null         $booking  The booking.
	 */
	private static function save( int $id, array $sent, string $state, int $attempts, int $next_try, string $code, ?array $booking ): void {
		$token = BookingStore::claim( $id );
		if ( '' === $token ) {
			return;
		}
		BookingStore::save( $id, $token, $sent, $state, $attempts, $next_try, $code );
		$ref = self::ref( $booking, $id );
		if ( BookingStore::FAILED === $state ) {
			Alerts::open( $code, Config::ALERT_LEVEL_RED, $ref );
		} else {
			foreach ( array( BookingPlanner::PROBLEM_NO_EMAIL, BookingPlanner::PROBLEM_UNMATCHED, BookingPlanner::PROBLEM_TOO_LONG, Messages::BOOKING_FAILED, Messages::BOOKING_REFUSED ) as $c ) {
				Alerts::resolve( $c, $ref );
			}
		}
	}

	/**
	 * "accom:num" → HostelMate room and bed, from the room matching.
	 *
	 * @return array<string, array{room: string, bed: string}>
	 */
	public static function targets(): array {
		$out = array();
		foreach ( Mapping::get() as $room => $list ) {
			foreach ( $list as $u ) {
				$out[ $u['accom'] . ':' . $u['num'] ] = array(
					'room' => $room,
					'bed'  => $u['bed'],
				);
			}
		}
		return $out;
	}

	/**
	 * The HostelMate room and bed a website booking goes to, or null when its HBook room isn't matched.
	 *
	 * @param array<string, array{room: string, bed: string}> $targets From targets().
	 * @param Booking|null                                    $booking The booking.
	 * @return array{room: string, bed: string}|null
	 */
	public static function target( array $targets, ?array $booking ): ?array {
		return null === $booking ? null : ( $targets[ $booking['accom_id'] . ':' . $booking['accom_num'] ] ?? null );
	}

	/**
	 * How a booking is named in alerts: its HBook number, never the guest.
	 *
	 * @param Booking|null $booking The booking.
	 * @param int          $id      Reservation id.
	 */
	private static function ref( ?array $booking, int $id ): string {
		return 'HBook #' . ( null !== $booking && '' !== $booking['number'] ? $booking['number'] : (string) $id );
	}
}
