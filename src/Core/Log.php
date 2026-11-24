<?php
/**
 * The plugin's activity log: codes and ids only, never guest data or secrets.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;

defined( 'ABSPATH' ) || exit;

final class Log {

	private const LEVELS = array( Config::LOG_LEVEL_INFO, Config::LOG_LEVEL_WARN, Config::LOG_LEVEL_ERROR );

	/**
	 * Adds one row. Throws if the table can't be written, so Guard can fall back.
	 *
	 * @param string      $level   Config::LOG_LEVEL_*.
	 * @param string      $code    Messages::* code.
	 * @param int|null    $resa_id HBook reservation id, when the row is about one.
	 * @param string|null $room_id HostelMate room id, when the row is about one.
	 * @param string      $detail  Short technical detail. Never guest data.
	 */
	public static function write( string $level, string $code, ?int $resa_id = null, ?string $room_id = null, string $detail = '' ): void {
		global $wpdb;
		$row = array(
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'level'   => in_array( $level, self::LEVELS, true ) ? $level : Config::LOG_LEVEL_ERROR,
			'code'    => substr( preg_replace( '/[^a-z0-9_]/', '', $code ) ?? '', 0, 48 ),
			'resa_id' => $resa_id,
			'room_id' => ( null !== $room_id && Validate::is_uuid( $room_id ) ) ? $room_id : null,
			'detail'  => self::clean( $detail ),
		);
		$ok  = $wpdb->insert( Db::table( Config::TABLE_LOG ), $row, array( '%s', '%s', '%s', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) {
			throw new \RuntimeException( 'Log write failed.' );
		}
	}

	/**
	 * Newest rows first.
	 *
	 * @param int $limit  Rows to return (1..500).
	 * @param int $offset Rows to skip.
	 * @return list<array<string, mixed>>
	 */
	public static function recent( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = Db::table( Config::TABLE_LOG );
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, at, level, code, resa_id, room_id, detail FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $limit, max( 0, $offset ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** Drops rows older than Config::LOG_KEEP_DAYS, then keeps at most Config::LOG_KEEP_ROWS. */
	public static function prune(): void {
		global $wpdb;
		$table  = Db::table( Config::TABLE_LOG );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - Config::LOG_KEEP_DAYS * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE at < %s', $table, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keep_from = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, Config::LOG_KEEP_ROWS - 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $keep_from ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $keep_from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * One line, no control characters, at most Config::LOG_DETAIL_MAX characters.
	 *
	 * @param string $detail Raw detail.
	 */
	private static function clean( string $detail ): string {
		$one_line = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $detail ) ?? '';
		return mb_substr( trim( $one_line ), 0, Config::LOG_DETAIL_MAX );
	}
}
