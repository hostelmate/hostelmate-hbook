<?php
/**
 * HBook settings the plugin needs, read in one place.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

defined( 'ABSPATH' ) || exit;

final class Options {

	/** HBook's currency code, upper case ('' when unset). */
	public static function currency(): string {
		$c = get_option( 'hb_currency', '' );
		return is_string( $c ) ? strtoupper( trim( $c ) ) : '';
	}

	/** Calendars HBook still syncs by iCal (go-live checklist, plan D2). */
	public static function ical_calendar_count(): int {
		global $wpdb;
		$table = Schema::table( 'ical' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
