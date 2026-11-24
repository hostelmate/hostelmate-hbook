<?php
/**
 * The check before a website booking is saved (plan 8, D3). Runs first on HBook's booking
 * AJAX action. It only ever stops a booking when HostelMate clearly says the room is no longer
 * bookable; every other case (no answer in 3 s, over budget, odd input, our own error) lets
 * HBook carry on exactly as without the plugin. It answers only a fixed message.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\Validate;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Precheck {

	/** Hooks it in front of HBook's own handler (same action, earlier priority). */
	public static function register(): void {
		foreach ( array( 'wp_ajax_nopriv_', 'wp_ajax_' ) as $prefix ) {
			add_action( $prefix . Config::HBOOK_CREATE_ACTION, \HostelMate\HBook\Core\Guard::wrap( array( self::class, 'run' ) ), 1, 0 );
		}
	}

	/** The check. Returns without a word unless HostelMate says no. */
	public static function run(): void {
		if ( Config::MODE_LIVE !== Settings::mode() || ! Settings::precheck_on() ) {
			return;
		}
		$stay = self::read_request();
		if ( null === $stay ) {
			return;
		}
		$need = self::rooms_needed( $stay['accoms'] );
		if ( array() === $need ) {
			return; // Nothing matched: HostelMate isn't asked.
		}
		if ( ! self::within_budget() ) {
			self::log( Messages::PRECHECK_SKIPPED, null, 'budget' );
			return;
		}
		$result = Client::availability( $stay['check_in'], $stay['check_out'], Config::PRECHECK_TIMEOUT );
		if ( ! $result->is_ok() ) {
			self::log( Messages::PRECHECK_SKIPPED, null, $result->kind );
			return;
		}
		$rooms = (array) $result->data;
		foreach ( $need as $room => $count ) {
			$r = $rooms[ $room ] ?? null;
			if ( is_array( $r ) && ( ! $r['bookable'] || $r['restricted'] || $r['min_free'] < $count ) ) {
				self::log( Messages::PRECHECK_REFUSED, $room, $stay['check_in'] . ' ' . $stay['check_out'] );
				wp_send_json(
					array(
						'success'   => false,
						'error_msg' => esc_html( Messages::text( Messages::GUEST_JUST_BOOKED ) ),
					)
				);
			}
		}
	}

	/**
	 * Only the dates and the accommodation ids of HBook's booking request; anything odd = null.
	 * Admin bookings (hb-details-is-admin) are left to HBook.
	 *
	 * @return array{check_in: string, check_out: string, accoms: list<int>}|null
	 */
	private static function read_request(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only check in front of HBook's own handler, which does its own checks.
		$get = static fn( string $k ): string => isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		if ( 'yes' === $get( 'hb-details-is-admin' ) ) {
			return null;
		}
		$in  = $get( 'hb-details-check-in' );
		$out = $get( 'hb-details-check-out' );
		$ids = $get( 'hb-details-accom-ids' );
		// phpcs:enable
		if ( ! Validate::is_date( $in ) || ! Validate::is_date( $out ) || $out <= $in || 1 !== preg_match( '/^\d{1,18}(-\d{1,18}){0,' . ( Config::PRECHECK_MAX_ROOMS - 1 ) . '}\z/', $ids ) ) {
			return null;
		}
		return array(
			'check_in'  => $in,
			'check_out' => $out,
			'accoms'    => array_map( 'intval', explode( '-', $ids ) ),
		);
	}

	/**
	 * HostelMate room → how many units of it this booking takes (matched accommodations only).
	 *
	 * @param list<int> $accoms Accommodation ids in the request.
	 * @return array<string, int>
	 */
	private static function rooms_needed( array $accoms ): array {
		$room_of = array();
		foreach ( Mapping::get() as $room => $list ) {
			foreach ( $list as $u ) {
				$room_of[ $u['accom'] ] = $room;
			}
		}
		$need = array();
		foreach ( $accoms as $accom ) {
			if ( isset( $room_of[ $accom ] ) ) {
				$need[ $room_of[ $accom ] ] = ( $need[ $room_of[ $accom ] ] ?? 0 ) + 1;
			}
		}
		return $need;
	}

	/**
	 * A log row, at most Config::PRECHECK_LOGS_PER_MIN a minute: the precheck is public, so it must not
	 * be able to flood the log and push real entries out.
	 *
	 * @param string      $code   Messages code.
	 * @param string|null $room   HostelMate room id.
	 * @param string      $detail Detail (no guest data).
	 */
	private static function log( string $code, ?string $room, string $detail ): void {
		$key  = 'hostelmate_hbook_pc_log_' . (int) floor( time() / 60 );
		$used = (int) get_transient( $key );
		if ( $used < Config::PRECHECK_LOGS_PER_MIN ) {
			set_transient( $key, $used + 1, 120 );
			Log::write( Config::LOG_LEVEL_WARN, $code, null, $room, $detail );
		}
	}

	/**
	 * At most Config::PRECHECK_PER_IP a minute per IP and PRECHECK_PER_SITE for the site. Best effort:
	 * behind a proxy all guests share one IP, and when the budget is used up the check is skipped
	 * (the booking goes ahead), so a flood can't block real guests.
	 */
	private static function within_budget(): bool {
		$minute = (int) floor( time() / 60 );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		foreach ( array(
			'ip_' . md5( $ip ) => Config::PRECHECK_PER_IP,
			'site'             => Config::PRECHECK_PER_SITE,
		) as $who => $limit ) {
			$key  = 'hostelmate_hbook_pc_' . $who . '_' . $minute;
			$used = (int) get_transient( $key );
			if ( $used >= $limit ) {
				return false;
			}
			set_transient( $key, $used + 1, 120 );
		}
		return true;
	}
}
