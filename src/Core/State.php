<?php
/**
 * Small runtime facts the plugin keeps (table version, last runs, ...), in one non-autoloaded option.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;

defined( 'ABSPATH' ) || exit;

final class State {

	/**
	 * One value, or $fallback when it was never set.
	 *
	 * @param string $key      Name inside the state option.
	 * @param mixed  $fallback Returned when missing.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Sets one value; everything else in the option is kept.
	 *
	 * @param string $key   Name inside the state option.
	 * @param mixed  $value JSON-safe value (scalars and arrays only).
	 */
	public static function set( string $key, $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;
		update_option( Config::OPT_STATE, $all, false );
	}

	/**
	 * The whole state as an array; anything that isn't an array counts as empty.
	 *
	 * @return array<string, mixed>
	 */
	private static function all(): array {
		$value = get_option( Config::OPT_STATE, array() );
		return is_array( $value ) ? $value : array();
	}
}
