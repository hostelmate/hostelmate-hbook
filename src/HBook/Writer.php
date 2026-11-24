<?php
/**
 * The only code that writes into HBook's tables (plan 6.5). Called inside one
 * transaction by SyncRunner. It only ever deletes or changes rows that are listed in
 * the plugin's own table AND carry the plugin's marker.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Db;
use HostelMate\HBook\Core\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type PricePlan from \HostelMate\HBook\Types
 * @phpstan-import-type Seasons from \HostelMate\HBook\Types
 * @phpstan-import-type Rows from \HostelMate\HBook\Types
 * @phpstan-import-type Block from \HostelMate\HBook\Types
 */
final class Writer {

	/**
	 * Replaces the plugin's seasons and rates with the planned ones.
	 *
	 * @param PricePlan $plan    PricePlanner result.
	 * @param Seasons   $seasons HBook seasons before the change.
	 * @return array{seasons: int, rates: int}
	 */
	public static function replace_prices( array $plan, array $seasons ): array {
		self::delete_own_prices( $seasons );
		$links       = Prices::season_links();
		$new_seasons = 0;
		$new_rates   = 0;
		foreach ( $plan['seasons'] as $season ) {
			$season_id = self::insert(
				'seasons',
				array(
					'name'     => Config::SEASON_NAME,
					'priority' => 'high',
				),
				array( '%s', '%s' )
			);
			self::own( Owned::SEASON, $season_id );
			++$new_seasons;
			foreach ( $season['ranges'] as $range ) {
				self::insert(
					'seasons_dates',
					array(
						'season_id'  => $season_id,
						'start_date' => $range[0],
						'end_date'   => $range[1],
						'days'       => '0,1,2,3,4,5,6',
					),
					array( '%d', '%s', '%s', '%s' )
				);
			}
			foreach ( $season['rates'] as $rate ) {
				$rate_id = self::insert(
					'rates',
					array(
						'type'        => 'accom',
						'all_accom'   => 0,
						'all_seasons' => 0,
						'amount'      => $rate['amount'],
						'nights'      => 1,
					),
					array( '%s', '%d', '%d', '%s', '%d' )
				);
				self::own( Owned::RATE, $rate_id );
				++$new_rates;
				foreach ( $rate['accoms'] as $accom ) {
					self::insert(
						'rates_accom',
						array(
							'rate_id'  => $rate_id,
							'accom_id' => $accom,
						),
						array( '%d', '%d' ),
						false
					);
				}
				self::insert(
					'rates_seasons',
					array(
						'rate_id'   => $rate_id,
						'season_id' => $season_id,
					),
					array( '%d', '%d' ),
					false
				);
			}
			// Copy the normal season's links so stay rules, coupons, discounts and extra-person rates keep applying.
			$base = $links[ $season['base'] ] ?? array(
				'rules'       => array(),
				'coupons'     => array(),
				'discounts'   => array(),
				'extra_rates' => array(),
			);
			foreach ( $base['rules'] as $id ) {
				self::insert(
					'booking_rules_seasons',
					array(
						'rule_id'   => $id,
						'season_id' => $season_id,
					),
					array( '%d', '%d' ),
					false
				);
			}
			foreach ( $base['coupons'] as $id ) {
				self::insert(
					'coupons_seasons',
					array(
						'coupon_id' => $id,
						'season_id' => $season_id,
					),
					array( '%d', '%d' ),
					false
				);
			}
			foreach ( $base['discounts'] as $id ) {
				self::insert(
					'discounts_seasons',
					array(
						'discount_id' => $id,
						'season_id'   => $season_id,
					),
					array( '%d', '%d' ),
					false
				);
			}
			foreach ( $base['extra_rates'] as $id ) {
				self::insert(
					'rates_seasons',
					array(
						'rate_id'   => $id,
						'season_id' => $season_id,
					),
					array( '%d', '%d' ),
					false
				);
			}
		}
		return array(
			'seasons' => $new_seasons,
			'rates'   => $new_rates,
		);
	}

	/**
	 * Removes the plugin's seasons (with their dates and every link pointing to them) and its rates.
	 *
	 * @param Seasons $seasons HBook seasons.
	 */
	public static function delete_own_prices( array $seasons ): void {
		global $wpdb;
		$season_ids = Owned::season_ids( $seasons );
		$rate_ids   = self::own_rate_ids( $season_ids );
		if ( array() !== $season_ids ) {
			$ids = array_map( 'intval', $season_ids );
			foreach ( array( 'seasons_dates', 'rates_seasons', 'booking_rules_seasons', 'coupons_seasons', 'discounts_seasons' ) as $name ) {
				self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE season_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Schema::table( $name ) ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND name = %s', array_merge( array( Schema::table( 'seasons' ) ), $ids, array( Config::SEASON_NAME ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( array() !== $rate_ids ) {
			$ids = array_map( 'intval', $rate_ids );
			foreach ( array( 'rates_accom', 'rates_seasons' ) as $name ) {
				self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE rate_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Schema::table( $name ) ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Schema::table( 'rates' ) ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::forget( Owned::SEASON );
		self::forget( Owned::RATE );
	}

	/**
	 * Makes the plugin's future blocks equal to $wanted, unit by unit, touching only units that differ.
	 * A block that started in the past keeps its past part (its end moves to today).
	 *
	 * @param array<string, list<string>> $wanted Unit "accom:num" → nights to block.
	 * @param Rows                        $rows   HBook rows read before the change.
	 * @param list<string>                $units  Every matched unit (units no longer wanted lose their blocks).
	 * @param string                      $today  Today.
	 * @return array{units_changed: int}
	 */
	public static function sync_blocks( array $wanted, array $rows, array $units, string $today ): array {
		$mine    = self::own_blocks_by_unit( $rows );
		$changed = 0;
		foreach ( self::units_to_change( $wanted, $rows, $units, $today ) as $unit ) {
			$want = $wanted[ $unit ] ?? array();
			++$changed;
			foreach ( $mine[ $unit ] ?? array() as $b ) {
				self::retire_block( $b, $today );
			}
			list( $accom, $num ) = array_map( 'intval', explode( ':', $unit ) );
			foreach ( \HostelMate\HBook\Sync\PricePlanner::ranges( $want ) as $range ) {
				self::insert_block( $accom, $num, $range[0], Clock::add_days( $range[1], 1 ) );
			}
		}
		return array( 'units_changed' => $changed );
	}

	/**
	 * Units whose plugin blocks, from today on, differ from what is wanted. Empty = nothing to write.
	 *
	 * @param array<string, list<string>> $wanted Unit → nights to block.
	 * @param Rows                        $rows   HBook rows.
	 * @param list<string>                $units  Every matched unit.
	 * @param string                      $today  Today.
	 * @return list<string>
	 */
	public static function units_to_change( array $wanted, array $rows, array $units, string $today ): array {
		$mine = self::own_blocks_by_unit( $rows );
		$out  = array();
		foreach ( array_unique( array_merge( $units, array_keys( $mine ), array_keys( $wanted ) ) ) as $unit ) {
			if ( self::future_nights( $mine[ $unit ] ?? array(), $today ) !== ( $wanted[ $unit ] ?? array() ) ) {
				$out[] = $unit;
			}
		}
		return $out;
	}

	/**
	 * The plugin's blocks (listed AND marked), by unit.
	 *
	 * @param Rows $rows HBook rows.
	 * @return array<string, list<Block>>
	 */
	private static function own_blocks_by_unit( array $rows ): array {
		$listed = array_flip( Owned::ids( Owned::BLOCK ) );
		$mine   = array();
		foreach ( $rows['blocks'] as $b ) {
			if ( Occupancy::is_own_block( $b ) && isset( $listed[ $b['id'] ] ) ) {
				$mine[ $b['accom_id'] . ':' . $b['accom_num'] ][] = $b;
			}
		}
		return $mine;
	}

	/** Remove / uninstall: every season, rate and block the plugin wrote, in one transaction; then caches are cleared. */
	public static function remove_everything(): void {
		Db::transaction(
			static function (): void {
				self::delete_own_prices( Prices::seasons() );
				self::delete_all_blocks( Occupancy::read( '1970-01-01', '2999-12-31' ) );
				Tools::restore_starting_prices();
			}
		);
		\HostelMate\HBook\Integrations\Cache::purge();
	}

	/**
	 * Removes every block the plugin wrote (Remove / uninstall), past ones included.
	 *
	 * @param Rows $rows HBook rows.
	 */
	public static function delete_all_blocks( array $rows ): void {
		$listed = array_flip( Owned::ids( Owned::BLOCK ) );
		foreach ( $rows['blocks'] as $b ) {
			if ( Occupancy::is_own_block( $b ) && isset( $listed[ $b['id'] ] ) ) {
				self::delete_block( $b['id'] );
			}
		}
		self::forget( Owned::BLOCK );
	}

	/**
	 * Nights from today on that the plugin's blocks of one unit cover.
	 *
	 * @param list<Block> $blocks The unit's plugin blocks.
	 * @param string      $today  Today.
	 * @return list<string>
	 */
	private static function future_nights( array $blocks, string $today ): array {
		$set = array();
		foreach ( $blocks as $b ) {
			foreach ( Occupancy::block_nights( $b, $today, Clock::add_days( $today, Config::HORIZON_CAP_DAYS + 400 ) ) as $n ) {
				$set[ $n ] = true;
			}
		}
		$list = array_keys( $set );
		sort( $list );
		return $list;
	}

	/**
	 * A plugin block leaves the future: deleted when it hasn't started, cut at today when it is running.
	 *
	 * @param Block  $b     One of the plugin's blocks.
	 * @param string $today Today.
	 */
	private static function retire_block( array $b, string $today ): void {
		global $wpdb;
		if ( $b['to_date'] <= $today ) {
			return; // Entirely in the past: history, left alone.
		}
		if ( $b['from_date'] < $today ) {
			$table = Schema::table( 'accom_blocked' );
			self::ok( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET to_date = %s WHERE id = %d AND uid LIKE %s', $table, $today, $b['id'], $wpdb->esc_like( Config::BLOCK_UID_PREFIX ) . '%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		self::delete_block( $b['id'] );
	}

	/**
	 * Deletes one plugin block (both marks checked again in the query).
	 *
	 * @param int $id Block id.
	 */
	private static function delete_block( int $id ): void {
		global $wpdb;
		$table = Schema::table( 'accom_blocked' );
		self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND uid LIKE %s', $table, $id, $wpdb->esc_like( Config::BLOCK_UID_PREFIX ) . '%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$owned = Db::table( Config::TABLE_OWNED );
		self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE kind = %s AND hbook_id = %d', $owned, Owned::BLOCK, $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One block on one named unit; $to is the day after the last night (never equal to $from).
	 *
	 * @param int    $accom Accommodation id.
	 * @param int    $num   Unit number.
	 * @param string $from  First night.
	 * @param string $to    Day after the last night.
	 */
	private static function insert_block( int $accom, int $num, string $from, string $to ): void {
		if ( $to <= $from ) {
			return;
		}
		$id = self::insert(
			'accom_blocked',
			array(
				'accom_id'       => $accom,
				'accom_all_ids'  => 0,
				'accom_num'      => $num,
				'accom_all_num'  => 0,
				'from_date'      => $from,
				'to_date'        => $to,
				'linked_resa_id' => 0,
				'is_prepa_time'  => 0,
				'comment'        => Config::BLOCK_COMMENT,
				'uid'            => Config::BLOCK_UID_PREFIX . Ids::uuid4(),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		self::own( Owned::BLOCK, $id );
	}

	/**
	 * The plugin's rates: listed, and linked to no season except the plugin's own.
	 *
	 * @param list<int> $own_seasons The plugin's season ids.
	 * @return list<int>
	 */
	private static function own_rate_ids( array $own_seasons ): array {
		$listed = array_flip( Owned::ids( Owned::RATE ) );
		$out    = array();
		foreach ( Prices::rates() as $rate ) {
			if ( isset( $listed[ $rate['id'] ] ) && array() !== $rate['seasons'] && array() === array_diff( $rate['seasons'], $own_seasons ) ) {
				$out[] = $rate['id'];
			}
		}
		return $out;
	}

	/**
	 * Inserts a row into an HBook table; throws on failure so the transaction rolls back.
	 *
	 * @param string               $name    Schema table key.
	 * @param array<string, mixed> $row     Column → value.
	 * @param list<string>         $formats wpdb formats.
	 * @param bool                 $has_id  The table has an auto-increment id to return.
	 */
	private static function insert( string $name, array $row, array $formats, bool $has_id = true ): int {
		global $wpdb;
		if ( false === $wpdb->insert( Schema::table( $name ), $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new \RuntimeException( 'HBook insert failed.' );
		}
		return $has_id ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Checks a write query's result; throws on failure so the transaction rolls back.
	 *
	 * @param int|bool $result What $wpdb->query() returned.
	 */
	private static function ok( int|bool $result ): void {
		if ( false === $result ) {
			throw new \RuntimeException( 'HBook write failed.' );
		}
	}

	/**
	 * Lists a row the plugin wrote.
	 *
	 * @param string $kind Owned::*.
	 * @param int    $id   HBook id.
	 */
	private static function own( string $kind, int $id ): void {
		global $wpdb;
		if ( false === $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			Db::table( Config::TABLE_OWNED ),
			array(
				'kind'       => $kind,
				'hbook_id'   => $id,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s' )
		) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new \RuntimeException( 'Owned list write failed.' );
		}
	}

	/**
	 * Empties the plugin's list for one kind.
	 *
	 * @param string $kind Owned::*.
	 */
	private static function forget( string $kind ): void {
		global $wpdb;
		$owned = Db::table( Config::TABLE_OWNED );
		self::ok( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE kind = %s', $owned, $kind ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
