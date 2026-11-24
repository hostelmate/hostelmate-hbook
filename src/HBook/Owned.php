<?php
/**
 * The HBook rows the plugin wrote, as listed in its own table. A row only counts as the
 * plugin's when it is listed here AND carries the plugin's marker (season name, block uid):
 * two checks before anything is ever deleted.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Db;

defined( 'ABSPATH' ) || exit;

final class Owned {

	public const SEASON         = 'season';
	public const RATE           = 'rate';
	public const BLOCK          = 'block';
	public const STARTING_PRICE = 'starting_price';

	/**
	 * Listed ids of one kind.
	 *
	 * @param string $kind One of the constants above.
	 * @return list<int>
	 */
	public static function ids( string $kind ): array {
		global $wpdb;
		$table = Db::table( Config::TABLE_OWNED );
		return array_values( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT hbook_id FROM `{$table}` WHERE kind = %s", $kind ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Config only.
	}

	/**
	 * The plugin's seasons: listed AND named Config::SEASON_NAME.
	 *
	 * @param array<int, array{name: string}> $seasons HBook\Prices::seasons().
	 * @return list<int>
	 */
	public static function season_ids( array $seasons ): array {
		$out = array();
		foreach ( self::ids( self::SEASON ) as $id ) {
			if ( isset( $seasons[ $id ] ) && Config::SEASON_NAME === $seasons[ $id ]['name'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
