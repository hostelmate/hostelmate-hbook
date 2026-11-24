<?php
/**
 * The plugin's own tables, transactions and named locks. The only place that
 * creates, upgrades or drops the plugin's tables.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Db {

	/**
	 * Full table name with the site prefix. Only names from Config are accepted.
	 *
	 * @param string $name One of Config::TABLES.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, Config::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown plugin table.' );
		}
		return $wpdb->prefix . $name;
	}

	/** Creates or updates the plugin's tables, then records the version. */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // @phpstan-ignore requireOnce.fileNotFound (WordPress core file)
		dbDelta( self::schema() );
		State::set( 'db_version', Config::DB_VERSION );
	}

	/** Runs install() once after an update that raised Config::DB_VERSION. */
	public static function maybe_upgrade(): void {
		if ( (int) State::get( 'db_version', 0 ) >= Config::DB_VERSION ) {
			return;
		}
		self::install();
		Log::write( Config::LOG_LEVEL_INFO, Messages::DB_UPGRADED );
	}

	/** Drops the plugin's own tables (uninstall only). */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( Config::TABLES as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Config only.
		}
	}

	/**
	 * Runs $work inside one transaction. Any throw rolls everything back and is rethrown.
	 *
	 * @param callable $work Does the writes.
	 */
	public static function transaction( callable $work ): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$work();
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}
	}

	/**
	 * Takes a named lock without waiting. False when another run holds it.
	 *
	 * @param string $name Config::LOCK_*.
	 */
	public static function lock( string $name ): bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $got;
	}

	/**
	 * Releases a lock taken with lock().
	 *
	 * @param string $name Config::LOCK_*.
	 */
	public static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * MySQL lock names are server-wide: make ours unique per database and site prefix.
	 *
	 * @param string $name Config::LOCK_*.
	 */
	private static function lock_name( string $name ): string {
		global $wpdb;
		return 'hm_hbook_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|' . $name ), 0, 40 );
	}

	/** CREATE TABLE statements in dbDelta's format (two spaces after PRIMARY KEY). */
	private static function schema(): string {
		global $wpdb;
		$c        = $wpdb->get_charset_collate();
		$owned    = self::table( Config::TABLE_OWNED );
		$bookings = self::table( Config::TABLE_BOOKINGS );
		$log      = self::table( Config::TABLE_LOG );

		return "CREATE TABLE {$owned} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  hbook_id bigint(20) unsigned NOT NULL,
  note text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kind_hbook (kind,hbook_id)
) ENGINE=InnoDB {$c};
CREATE TABLE {$bookings} (
  hbook_resa_id bigint(20) unsigned NOT NULL,
  hm_booking_id char(36) NOT NULL,
  hm_guest_id char(36) NULL,
  sent_state longtext NULL,
  state varchar(16) NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_try_at datetime NULL,
  last_code varchar(48) NULL,
  claim_token char(32) NULL,
  claimed_until datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (hbook_resa_id),
  UNIQUE KEY hm_booking_id (hm_booking_id),
  KEY state_next (state,next_try_at)
) ENGINE=InnoDB {$c};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at datetime NOT NULL,
  level varchar(8) NOT NULL,
  code varchar(48) NOT NULL,
  resa_id bigint(20) unsigned NULL,
  room_id char(36) NULL,
  detail varchar(190) NULL,
  PRIMARY KEY  (id),
  KEY at (at)
) ENGINE=InnoDB {$c};";
	}
}
