<?php
/**
 * Wraps every hook callback so an error inside the plugin can never take the website down.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * A callback that runs $callback and catches everything it throws.
	 * For filters the first argument is handed back untouched on error.
	 *
	 * @param callable $callback The real callback.
	 */
	public static function wrap( callable $callback ): \Closure {
		return static function ( ...$args ) use ( $callback ) {
			try {
				return $callback( ...$args );
			} catch ( \Throwable $e ) {
				self::report( $e );
				return $args[0] ?? null;
			}
		};
	}

	/**
	 * Logs the error class and place only: messages can carry data, so they are not stored.
	 *
	 * @param \Throwable $e The caught error.
	 */
	public static function report( \Throwable $e ): void {
		$detail = get_class( $e ) . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
		try {
			Log::write( Config::LOG_LEVEL_ERROR, Messages::GUARD_CAUGHT, null, null, $detail );
		} catch ( \Throwable $ignored ) {
			// The log itself failed (for example before the tables exist): fall back to PHP's log.
			error_log( '[' . Config::SLUG . '] ' . Messages::GUARD_CAUGHT . ' ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort trace, no data.
		}
	}
}
