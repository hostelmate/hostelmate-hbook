<?php
/**
 * Everything the plugin knows about HBook's tables, declared once, and the check
 * that they are still what we expect (HBook updates change internals: HBook 2.2
 * removed update_resa_paid(), which some sites' own add-ons still call).
 * No other class spells an HBook table name.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** HBook versions this plugin was tested on. Others run if the tables match, with a notice. */
	public const TESTED_VERSIONS = array( '2.2.1' );

	/** Tables the plugin writes to: these must be InnoDB so a whole plan commits or rolls back. */
	public const WRITTEN = array( 'seasons', 'seasons_dates', 'rates', 'rates_accom', 'rates_seasons', 'booking_rules_seasons', 'coupons_seasons', 'discounts_seasons', 'accom_blocked' );

	/**
	 * HBook table (without "wp_hb_") → the columns the plugin reads or writes.
	 *
	 * @return array<string, list<string>>
	 */
	public static function tables(): array {
		return array(
			'resa'                  => array( 'id', 'check_in', 'check_out', 'accom_id', 'accom_num', 'adults', 'children', 'price', 'status', 'customer_id', 'origin', 'parent_id', 'alphanum_id', 'payments_logs', 'paid', 'payment_gateway', 'synchro_id', 'updated_on' ),
			'accom_blocked'         => array( 'id', 'accom_id', 'accom_all_ids', 'accom_num', 'accom_all_num', 'from_date', 'to_date', 'linked_resa_id', 'is_prepa_time', 'comment', 'uid' ),
			'accom_num_name'        => array( 'accom_id', 'accom_num', 'num_name' ),
			'seasons'               => array( 'id', 'name', 'priority' ),
			'seasons_dates'         => array( 'id', 'season_id', 'start_date', 'end_date', 'days' ),
			'rates'                 => array( 'id', 'type', 'all_accom', 'all_seasons', 'amount', 'nights' ),
			'rates_accom'           => array( 'rate_id', 'accom_id' ),
			'rates_seasons'         => array( 'rate_id', 'season_id' ),
			'rates_rules'           => array( 'rate_id', 'rule_id' ),
			'booking_rules_seasons' => array( 'rule_id', 'season_id' ),
			'coupons_seasons'       => array( 'coupon_id', 'season_id' ),
			'discounts_seasons'     => array( 'discount_id', 'season_id' ),
			'parents_resa'          => array( 'id', 'price', 'customer_id', 'alphanum_id', 'payments_logs', 'paid', 'payment_gateway' ),
			'customers'             => array( 'id', 'info' ),
			'ical'                  => array( 'synchro_id', 'accom_id', 'accom_num', 'calendar_name' ),
		);
	}

	/**
	 * Full table name for one of tables().
	 *
	 * @param string $name Key of tables().
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! array_key_exists( $name, self::tables() ) ) {
			throw new \InvalidArgumentException( 'Unknown HBook table.' );
		}
		return $wpdb->prefix . 'hb_' . $name;
	}

	/** HBook's version as it reports it ('' when HBook isn't installed). */
	public static function version(): string {
		$v = get_option( 'hbook_version', '' );
		return is_string( $v ) ? $v : '';
	}

	/** True when this HBook version was tested. */
	public static function is_tested_version(): bool {
		return in_array( self::version(), self::TESTED_VERSIONS, true );
	}

	/**
	 * Every way HBook's tables differ from what the plugin needs. Empty = all good.
	 * Each problem is "missing_table:x", "missing_column:x.y" or "not_innodb:x". Schema::summary() words them.
	 *
	 * @return list<string>
	 */
	public static function problems(): array {
		global $wpdb;
		$problems = array();
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'hb_' ) . '%'
			),
			ARRAY_A
		);
		$engines  = array();
		foreach ( (array) $rows as $row ) {
			$engines[ (string) $row['t'] ] = strtolower( (string) $row['e'] );
		}
		foreach ( self::tables() as $name => $columns ) {
			$table = self::table( $name );
			if ( ! isset( $engines[ $table ] ) ) {
				$problems[] = 'missing_table:' . $name;
				continue;
			}
			if ( in_array( $name, self::WRITTEN, true ) && 'innodb' !== $engines[ $table ] ) {
				$problems[] = 'not_innodb:' . $name;
			}
			$have = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( array_diff( $columns, array_map( 'strval', (array) $have ) ) as $missing ) {
				$problems[] = 'missing_column:' . $name . '.' . $missing;
			}
		}
		return $problems;
	}

	/**
	 * The first problem, readable and with the full table name (for the owner's host), plus how many more. Pure.
	 *
	 * @param list<string> $problems From problems().
	 */
	public static function summary( array $problems ): string {
		if ( array() === $problems ) {
			return '';
		}
		list( $kind, $what ) = array_pad( explode( ':', $problems[0], 2 ), 2, '' );
		$label               = array(
			'not_innodb'     => 'not InnoDB',
			'missing_table'  => 'missing table',
			'missing_column' => 'missing column',
		)[ $kind ] ?? $kind;
		$more                = count( $problems ) > 1 ? ' (+' . ( count( $problems ) - 1 ) . ' more)' : '';
		$parts               = explode( '.', $what, 2 );
		return mb_substr( $label . ': ' . self::table( $parts[0] ) . ( isset( $parts[1] ) ? '.' . $parts[1] : '' ), 0, 64 - strlen( $more ) ) . $more;
	}
}
