<?php
/**
 * "Copy diagnostics": what support needs to help, and nothing else. Never the key, never guest
 * data, never URLs with secrets.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\BookingStore;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Options;
use HostelMate\HBook\HBook\Schema;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {

	/** Plain text, one fact per line. */
	public static function text(): string {
		$states = array();
		foreach ( BookingStore::all() as $row ) {
			$states[ $row['state'] ] = ( $states[ $row['state'] ] ?? 0 ) + 1;
		}
		$last  = State::get( 'last_run' );
		$lines = array(
			'plugin ' . Config::VERSION . ' · tables v' . (int) State::get( 'db_version', 0 ),
			'wordpress ' . get_bloginfo( 'version' ) . ' · php ' . PHP_VERSION . ' · hbook ' . Schema::version() . ( Schema::is_tested_version() ? ' (tested)' : ' (not tested)' ),
			'mode ' . Settings::mode() . ' · key ' . ( Secret::from_constant() ? 'in wp-config' : ( Secret::has() ? 'stored' : 'none' ) ) . ' · check before booking ' . ( Settings::precheck_on() ? 'on' : 'off' ),
			'currency hbook ' . Options::currency() . ' · ical calendars ' . Options::ical_calendar_count() . ' · zone ' . wp_timezone_string(),
			'matched units ' . array_sum( array_map( 'count', Mapping::get() ) ),
			'last run ' . ( is_array( $last ) ? gmdate( 'Y-m-d H:i', (int) $last['at'] ) . 'Z ' . (string) $last['code'] : 'never' ) . ' · last ok ' . ( is_int( State::get( 'last_ok' ) ) ? gmdate( 'Y-m-d H:i', (int) State::get( 'last_ok' ) ) . 'Z' : 'never' ),
			'next sync ' . self::next( Config::CRON_SYNC ) . ' · next bookings ' . self::next( Config::CRON_BOOKINGS ) . ' · wp-cron ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled (server cron?)' : 'on visits' ),
			'bookings ' . ( array() === $states ? 'none' : http_build_query( $states, '', ' ' ) ),
			'schema ' . ( array() === Schema::problems() ? 'ok' : implode( ' ', Schema::problems() ) ),
			'alerts ' . implode( ' ', array_map( static fn( array $a ): string => $a['level'] . ':' . $a['code'], Alerts::list() ) ),
			'--- last 50 log lines (codes only)',
		);
		foreach ( Log::recent( 50 ) as $row ) {
			$lines[] = $row['at'] . 'Z ' . $row['level'] . ' ' . $row['code'] . ( null !== $row['resa_id'] ? ' #' . $row['resa_id'] : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * When a cron event runs next.
	 *
	 * @param string $hook Cron hook.
	 */
	private static function next( string $hook ): string {
		$t = wp_next_scheduled( $hook );
		return false === $t ? 'not scheduled' : gmdate( 'H:i', $t ) . 'Z';
	}
}
