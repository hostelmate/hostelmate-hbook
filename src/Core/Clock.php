<?php
/**
 * Dates, declared once. All dates are calendar days written YYYY-MM-DD in the
 * property's own time zone; maths is done on dates only, so no time-zone shifts.
 * A stay's check-out day is never a night.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

defined( 'ABSPATH' ) || exit;

final class Clock {

	/** The site's time zone (WordPress setting). */
	public static function site_zone(): \DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Today's date in a time zone.
	 *
	 * @param \DateTimeZone $zone Time zone.
	 * @param int|null      $now  Unix time to use instead of the clock (tests).
	 */
	public static function today( \DateTimeZone $zone, ?int $now = null ): string {
		return ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $zone )->format( 'Y-m-d' );
	}

	/**
	 * $date plus $days (negative goes back).
	 *
	 * @param string $date YYYY-MM-DD.
	 * @param int    $days Days to add.
	 */
	public static function add_days( string $date, int $days ): string {
		return self::parse( $date )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Whole days from $from to $to (negative when $to is earlier).
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD.
	 */
	public static function days_between( string $from, string $to ): int {
		return (int) self::parse( $from )->diff( self::parse( $to ) )->format( '%r%a' );
	}

	/**
	 * The nights of a stay: check-in included, check-out excluded.
	 *
	 * @param string $check_in  YYYY-MM-DD.
	 * @param string $check_out YYYY-MM-DD, after $check_in.
	 * @return list<string>
	 */
	public static function nights( string $check_in, string $check_out ): array {
		$count = self::days_between( $check_in, $check_out );
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = self::add_days( $check_in, $i );
		}
		return $out;
	}

	/**
	 * A strict date in UTC (the zone doesn't matter for date-only maths, it only has to be fixed).
	 *
	 * @param string $date YYYY-MM-DD.
	 */
	private static function parse( string $date ): \DateTimeImmutable {
		if ( ! Validate::is_date( $date ) ) {
			throw new \InvalidArgumentException( 'Not a YYYY-MM-DD date.' );
		}
		return new \DateTimeImmutable( $date . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
	}
}
