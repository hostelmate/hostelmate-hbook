<?php
/**
 * How far ahead HBook sells, the way HBook decides it (database-actions.php:1980-2007):
 * the room's own fixed date or months ahead, else the site's, else 36 months.
 * Read through get_option/get_post_meta so other plugins' filters apply.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Validate;

defined( 'ABSPATH' ) || exit;

final class Dates {

	/**
	 * The last date HBook lets a guest pick, for one accommodation.
	 *
	 * @param int    $accom_id Accommodation id.
	 * @param string $today    Today (site time zone).
	 */
	public static function max_date( int $accom_id, string $today ): string {
		$fixed  = get_post_meta( $accom_id, 'max_date_fixed', true );
		$months = (int) get_post_meta( $accom_id, 'max_date_months', true );
		if ( ! Validate::is_date( $fixed ) && $months <= 0 ) {
			$fixed  = get_option( 'hb_max_date_fixed' );
			$months = (int) get_option( 'hb_max_date_months' );
			$months = $months > 0 ? $months : 36;
		}
		if ( Validate::is_date( $fixed ) ) {
			return $fixed;
		}
		return ( new \DateTimeImmutable( $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $months . ' month' )->format( 'Y-m-d' );
	}

	/**
	 * The last night the plugin keeps in step: the latest date any accommodation can be sold, plus
	 * $pad days, never past $cap days from today, never before today.
	 *
	 * @param list<int> $accom_ids Accommodation ids.
	 * @param string    $today     Today.
	 * @param int       $pad       Extra days.
	 * @param int       $cap       Furthest day from today.
	 */
	public static function horizon_end( array $accom_ids, string $today, int $pad, int $cap ): string {
		$last = $today;
		foreach ( $accom_ids as $id ) {
			$last = max( $last, self::max_date( $id, $today ) );
		}
		return min( Clock::add_days( $last, $pad ), Clock::add_days( $today, $cap ) );
	}
}
