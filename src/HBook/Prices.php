<?php
/**
 * HBook's seasons and rates (read side, P3). HBook prices a night by its season:
 * priority 'high', then '', then 'low'; inside a priority the first matching date row
 * whose weekday list contains the night wins (database-actions.php:1189-1213, weekday
 * 0 = Monday). This class picks it the same way, optionally skipping the plugin's own seasons.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Seasons from \HostelMate\HBook\Types
 * @phpstan-import-type Rate from \HostelMate\HBook\Types
 */
final class Prices {

	/** HBook's season priorities, in the order it tries them. */
	public const PRIORITIES = array( 'high', '', 'low' );

	/**
	 * Every season with its date rows, in id order.
	 *
	 * @return Seasons
	 */
	public static function seasons(): array {
		global $wpdb;
		$s_table = Schema::table( 'seasons' );
		$d_table = Schema::table( 'seasons_dates' );
		$seasons = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name, priority FROM `{$s_table}` ORDER BY id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema only.
			$seasons[ (int) $row['id'] ] = array(
				'id'       => (int) $row['id'],
				'name'     => (string) $row['name'],
				'priority' => (string) $row['priority'],
				'dates'    => array(),
			);
		}
		foreach ( (array) $wpdb->get_results( "SELECT id, season_id, start_date, end_date, days FROM `{$d_table}` ORDER BY id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema only.
			$id = (int) $row['season_id'];
			if ( isset( $seasons[ $id ] ) ) {
				$seasons[ $id ]['dates'][] = array(
					'row_id' => (int) $row['id'],
					'start'  => (string) $row['start_date'],
					'end'    => (string) $row['end_date'],
					'days'   => array_values( array_map( 'intval', array_filter( explode( ',', (string) $row['days'] ), static fn( string $d ): bool => '' !== $d ) ) ),
				);
			}
		}
		return $seasons;
	}

	/**
	 * The season HBook would use for a night, or 0 when none matches.
	 * Pure: works on what seasons() returned.
	 *
	 * @param Seasons $seasons From seasons().
	 * @param string                                                                                            $night   YYYY-MM-DD.
	 * @param list<int>                                                                                         $skip    Season ids to ignore (the plugin's own).
	 * @param list<string>                                                                                      $priorities Priorities to try, in order (default: HBook's own order).
	 */
	public static function season_for( array $seasons, string $night, array $skip = array(), array $priorities = self::PRIORITIES ): int {
		return self::season_per_night( $seasons, array( $night ), $skip, $priorities )[ $night ];
	}

	/**
	 * season_for() for many nights at once: each date row is read once, not once per night, so a
	 * horizon of 730 nights over the plugin's own 730 one-night seasons stays fast. Pure.
	 *
	 * @param Seasons      $seasons    From seasons().
	 * @param list<string> $nights     YYYY-MM-DD, any order.
	 * @param list<int>    $skip       Season ids to ignore (the plugin's own).
	 * @param list<string> $priorities Priorities to try, in order (default: HBook's own order).
	 * @return array<string, int> Night → season id (0 when none matches), for every night given.
	 */
	public static function season_per_night( array $seasons, array $nights, array $skip = array(), array $priorities = self::PRIORITIES ): array {
		$sorted = array_values( array_unique( $nights ) );
		sort( $sorted, SORT_STRING );
		$count = count( $sorted );
		$skip  = array_flip( $skip );
		$rank  = array_flip( $priorities );
		// Night → priority rank → candidates [season id, date-row id, weekdays].
		$candidates = array();
		foreach ( $seasons as $id => $season ) {
			if ( ! isset( $rank[ $season['priority'] ] ) || isset( $skip[ $id ] ) ) {
				continue;
			}
			foreach ( $season['dates'] as $row ) {
				for ( $i = self::first_at_or_after( $sorted, $row['start'] ); $i < $count && $sorted[ $i ] <= $row['end']; $i++ ) {
					$candidates[ $sorted[ $i ] ][ $rank[ $season['priority'] ] ][] = array( $id, $row['row_id'], $row['days'] );
				}
			}
		}
		$out = array();
		foreach ( $sorted as $night ) {
			$out[ $night ] = 0;
			$weekday       = (int) gmdate( 'N', (int) strtotime( $night . ' 00:00:00 UTC' ) ) - 1;
			$by_rank       = $candidates[ $night ] ?? array();
			ksort( $by_rank );
			foreach ( $by_rank as $list ) {
				// HBook's query has no ORDER BY; MySQL returns the date rows in id order, so the first match by date-row id wins.
				usort( $list, static fn( array $a, array $b ): int => $a[1] <=> $b[1] );
				foreach ( $list as $candidate ) {
					if ( in_array( $weekday, $candidate[2], true ) ) {
						$out[ $night ] = $candidate[0];
						continue 3;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Index of the first night >= $date in a sorted list (count when none). Pure.
	 *
	 * @param list<string> $sorted Nights, sorted.
	 * @param string       $date   YYYY-MM-DD.
	 */
	private static function first_at_or_after( array $sorted, string $date ): int {
		$lo = 0;
		$hi = count( $sorted );
		while ( $lo < $hi ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( $sorted[ $mid ] < $date ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		return $lo;
	}

	/**
	 * Every rate with its links, in id order.
	 *
	 * @return list<Rate>
	 */
	public static function rates(): array {
		global $wpdb;
		$rates = Schema::table( 'rates' );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, type, amount, nights FROM `{$rates}` ORDER BY id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema only.
			$out[ (int) $row['id'] ] = array(
				'id'      => (int) $row['id'],
				'type'    => (string) $row['type'],
				'amount'  => (string) $row['amount'],
				'nights'  => max( 1, (int) $row['nights'] ),
				'accoms'  => array(),
				'seasons' => array(),
				'rules'   => array(),
			);
		}
		foreach ( array(
			'rates_accom'   => array( 'accom_id', 'accoms' ),
			'rates_seasons' => array( 'season_id', 'seasons' ),
			'rates_rules'   => array( 'rule_id', 'rules' ),
		) as $name => list( $column, $field ) ) {
			$table = Schema::table( $name );
			foreach ( (array) $wpdb->get_results( "SELECT rate_id, `{$column}` AS v FROM `{$table}`", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifiers from Schema only.
				$id = (int) $row['rate_id'];
				if ( isset( $out[ $id ] ) ) {
					$out[ $id ][ $field ][] = (int) $row['v'];
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * The one-night accommodation price HBook uses for (accommodation, season), as a decimal
	 * string, or null when none. Rule rates and package rates (nights > 1) are left out. Pure.
	 *
	 * @param list<Rate> $rates From rates().
	 * @param int                                                                                                           $accom Accommodation id.
	 * @param int                                                                                                           $season Season id.
	 */
	public static function night_rate( array $rates, int $accom, int $season ): ?string {
		foreach ( $rates as $rate ) {
			if ( self::is_night_rate( $rate ) && in_array( $accom, $rate['accoms'], true ) && in_array( $season, $rate['seasons'], true ) ) {
				return $rate['amount'];
			}
		}
		return null;
	}

	/**
	 * night_rate() for every (accommodation, season) pair at once: the same first match, built in one pass. Pure.
	 *
	 * @param list<Rate> $rates From rates().
	 * @return array<int, array<int, string>> Accommodation id → season id → amount.
	 */
	public static function night_rates( array $rates ): array {
		$out = array();
		foreach ( $rates as $rate ) {
			if ( ! self::is_night_rate( $rate ) ) {
				continue;
			}
			foreach ( $rate['accoms'] as $accom ) {
				foreach ( $rate['seasons'] as $season ) {
					$out[ $accom ][ $season ] ??= $rate['amount'];
				}
			}
		}
		return $out;
	}

	/**
	 * A plain one-night accommodation price (not a rule rate, not a package). Pure.
	 *
	 * @param Rate $rate One of rates().
	 */
	private static function is_night_rate( array $rate ): bool {
		return 'accom' === $rate['type'] && 1 === $rate['nights'] && array() === $rate['rules'];
	}

	/**
	 * Links of a season to other HBook objects (stay rules, coupons, discounts, extra-person rates),
	 * for copying onto the plugin's seasons.
	 *
	 * @return array<int, array{rules: list<int>, coupons: list<int>, discounts: list<int>, extra_rates: list<int>}> Season id → linked ids.
	 */
	public static function season_links(): array {
		global $wpdb;
		$out = array();
		foreach ( array(
			'booking_rules_seasons' => array( 'rule_id', 'rules' ),
			'coupons_seasons'       => array( 'coupon_id', 'coupons' ),
			'discounts_seasons'     => array( 'discount_id', 'discounts' ),
		) as $name => list( $column, $field ) ) {
			$table = Schema::table( $name );
			foreach ( (array) $wpdb->get_results( "SELECT season_id, `{$column}` AS v FROM `{$table}`", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifiers from Schema only.
				$out[ (int) $row['season_id'] ][ $field ][] = (int) $row['v'];
			}
		}
		foreach ( self::rates() as $rate ) {
			if ( in_array( $rate['type'], array( 'extra_adults', 'extra_children' ), true ) ) {
				foreach ( $rate['seasons'] as $season ) {
					$out[ $season ]['extra_rates'][] = $rate['id'];
				}
			}
		}
		foreach ( $out as $season => $links ) {
			$out[ $season ] = array(
				'rules'       => $links['rules'] ?? array(),
				'coupons'     => $links['coupons'] ?? array(),
				'discounts'   => $links['discounts'] ?? array(),
				'extra_rates' => $links['extra_rates'] ?? array(),
			);
		}
		return $out;
	}
}
