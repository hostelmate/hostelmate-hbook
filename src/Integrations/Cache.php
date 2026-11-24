<?php
/**
 * Clears page caches after prices or dates change (HBook's free-dates calendars are
 * written into the page HTML). Uses each cache plugin's own public purge call when present.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Integrations;

defined( 'ABSPATH' ) || exit;

final class Cache {

	/**
	 * Purges every page cache it knows.
	 *
	 * @return list<string> The names it purged.
	 */
	public static function purge(): array {
		$done = array();
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- other plugins' own hooks.
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$done[] = 'breeze';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'wp-rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done[] = 'w3-total-cache';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$done[] = 'litespeed';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done[] = 'wp-super-cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$done[] = 'siteground';
		}
		// phpcs:enable
		do_action( 'hostelmate_hbook_purge_cache' );
		return $done;
	}
}
