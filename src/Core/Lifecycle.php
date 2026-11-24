<?php
/**
 * Activation, deactivation and upgrades.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * Refuses politely when the site can't run the plugin; otherwise creates the tables.
	 *
	 * @param bool $network_wide True when activated for a whole multisite network.
	 */
	public static function activate( bool $network_wide = false ): void {
		$problems = self::requirements( $network_wide );
		if ( array() !== $problems ) {
			deactivate_plugins( plugin_basename( HOSTELMATE_HBOOK_FILE ) );
			$text = implode( ' ', array_map( array( Messages::class, 'text' ), $problems ) );
			wp_die( esc_html( $text ), '', array( 'back_link' => true ) );
		}
		Db::install();
	}

	/** Stops every scheduled run. Data stays until the plugin is deleted. */
	public static function deactivate(): void {
		foreach ( Config::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/** Runs on every load: upgrades the plugin's tables once after an update. */
	public static function upgrade(): void {
		Db::maybe_upgrade();
	}

	/**
	 * Message codes for every requirement that isn't met (empty when all are).
	 *
	 * @param bool $network_wide True when activated network-wide.
	 * @return list<string>
	 */
	public static function requirements( bool $network_wide = false ): array {
		$problems = array();
		if ( version_compare( PHP_VERSION, Config::MIN_PHP, '<' ) ) {
			$problems[] = Messages::REQ_PHP;
		}
		if ( version_compare( (string) get_bloginfo( 'version' ), Config::MIN_WP, '<' ) ) {
			$problems[] = Messages::REQ_WP;
		}
		if ( $network_wide ) {
			$problems[] = Messages::REQ_MULTISITE;
		}
		return $problems;
	}
}
